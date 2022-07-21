<?php

declare(strict_types=1);

namespace Webclient\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;
use Webclient\Http\Exception\CanNotParseResponse;
use Webclient\Http\Exception\ConnectionError;
use Webclient\Http\Exception\ConnectionTimedOut;
use Webclient\Http\Exception\InvalidRequest;
use Webclient\Http\Exception\SslConnectionError;

final class Webclient implements ClientInterface
{
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;
    private float $timeout;

    public function __construct(
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        ?float $timeout = null
    ) {
        $this->responseFactory = $responseFactory;
        $this->streamFactory = $streamFactory;
        if (is_null($timeout)) {
            $timeout = (float)ini_get('default_socket_timeout');
        }
        $this->timeout = $timeout;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $requestUri = $request->getUri();
        $host = $requestUri->getHost();
        if ($host === '' && !$request->hasHeader('Host')) {
            throw new InvalidRequest($request, 'Request has not host');
        }
        if ($host === '') {
            $host = $request->getHeaderLine('Host');
        }
        $url = $requestUri->getPath();
        if ($url === '') {
            $url = '/';
        }
        $query = $requestUri->getQuery();
        if ($query !== '') {
            $url .= '?' . $query;
        }
        $fragment = $requestUri->getFragment();
        if ($fragment !== '') {
            $url .= '#' . $fragment;
        }
        $requestProtocolVersion = $request->getProtocolVersion();
        $method = $request->getMethod();

        $requestStream = fopen('php://temp', 'w+');
        fwrite($requestStream, sprintf('%s %s HTTP/%s%s', $method, $url, $requestProtocolVersion, "\r\n"));
        if (!$request->hasHeader('Host')) {
            fwrite($requestStream, sprintf('Host: %s%s', $host, "\r\n"));
        }
        fwrite($requestStream, "Connection: close\r\n");

        foreach ($request->getHeaders() as $header => $values) {
            if (strtolower($header) === 'connection') {
                continue;
            }
            if (strtolower($header) === 'content-length') {
                continue;
            }
            foreach ($values as $value) {
                fwrite($requestStream, sprintf('%s: %s%s', $header, $value, "\r\n"));
            }
        }

        $requestBodyStream = fopen('php://temp', 'w+');

        $requestBody = $request->getBody();
        $requestBody->rewind();
        while (!$requestBody->eof()) {
            fwrite($requestBodyStream, $requestBody->read(2048));
        }

        $requestContentLength = fstat($requestBodyStream)['size'];
        if ($requestContentLength > 0) {
            fwrite($requestStream, sprintf('%s: %d%s', 'Content-Length', $requestContentLength, "\r\n"));
        }
        fwrite($requestStream, "\r\n");

        rewind($requestBodyStream);
        stream_copy_to_stream($requestBodyStream, $requestStream);
        fclose($requestBodyStream);

        $secure = $requestUri->getScheme() === 'https';
        $port = $requestUri->getPort();
        if (is_null($port)) {
            $port = $secure ? 443 : 80;
        }

        $address = sprintf('tcp://%s:%d', $host, $port);
        try {
            $httpStream = stream_socket_client(
                $address,
                $errCode,
                $errMessage,
                null,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT
            );
        } catch (Throwable $exception) {
            $errMessage = $exception->getMessage();
            if (strpos($errMessage, 'stream_socket_client(): ') === 0) {
                $errMessage = substr($errMessage, 24);
            }
            if ($exception->getCode() === 110) {
                throw new ConnectionTimedOut($request, $errMessage);
            }
            throw new ConnectionError($request, $errMessage, $errCode);
        }

        if ($secure) {
            $mask = STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT
                | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_SSLv23_CLIENT;

            try {
                stream_socket_enable_crypto($httpStream, true, $mask);
            } catch (Throwable $exception) {
                $errMessage = $exception->getMessage();
                if (strpos($errMessage, 'stream_socket_enable_crypto(): ') === 0) {
                    $errMessage = substr($errMessage, 31);
                }
                $errMessage = sprintf('ssl connection error: %s', $errMessage);
                throw new SslConnectionError($request, $errMessage);
            }
        }

        [$timeOutSeconds, $timeOutMicroseconds] = array_replace([0, 0], explode('.', (string)$this->timeout, 2));
        stream_set_timeout($httpStream, (int)$timeOutSeconds, (int)$timeOutMicroseconds);

        rewind($requestStream);
        stream_copy_to_stream($requestStream, $httpStream);
        fclose($requestStream);

        $header = '';
        $headers = [];
        $headerRead = false;
        while (!feof($httpStream) && !$headerRead) {
            $part = fread($httpStream, 1);
            $header .= $part;
            if (strlen($header) >= 4 && substr($header, -4) === "\r\n\r\n") {
                $headerRead = true;
            }
        }

        $metadata = stream_get_meta_data($httpStream);
        $timedOut = $metadata['timed_out'] ?? false;
        if ($timedOut) {
            throw new ConnectionTimedOut($request, 'connection timed out');
        }

        $arr = explode("\r\n", substr($header, 0, -4));
        $responseProtocolVersion = $requestProtocolVersion;
        $responseStatusCode = null;
        $responseReasonPhrase = '';
        foreach ($arr as $line) {
            if (strpos($line, 'HTTP/') === 0) {
                $firstLineArray = explode(' ', $line, 3);
                $firstLineUnits = count($firstLineArray);
                if ($firstLineUnits < 2) {
                    throw new CanNotParseResponse($header);
                }
                $responseProtocolVersion = trim(explode('/', $firstLineArray[0], 2)[1]);
                $responseStatusCode = (int)$firstLineArray[1];
                if ($firstLineUnits === 3) {
                    $responseReasonPhrase = trim($firstLineArray[2]);
                }
                continue;
            }
            if (strpos($line, ':') === false) {
                continue;
            }
            [$h, $v] = explode(':', $line, 2);
            $headerName = strtolower(trim($h));
            $value = trim($v);
            if ($value === '') {
                continue;
            }
            if (array_key_exists($headerName, $headers)) {
                $headers[$headerName][] = $value;
            } else {
                $headers[$headerName] = [$value];
            }
        }

        if (is_null($responseStatusCode)) {
            throw new CanNotParseResponse($header);
        }
        $responseStream = $this->getResponseStream($request, $headers, $httpStream);
        $responseBody = $this->streamFactory->createStreamFromResource($responseStream);
        $response = $this->responseFactory->createResponse($responseStatusCode, $responseReasonPhrase)
            ->withBody($responseBody)
            ->withProtocolVersion($responseProtocolVersion)
        ;

        foreach ($headers as $header => $values) {
            $response = $response->withHeader($header, $values);
        }

        return $response;
    }

    /**
     * @param RequestInterface $request
     * @param array $headers
     * @param resource $source
     * @return resource
     */
    private function getResponseStream(RequestInterface $request, array $headers, $source)
    {
        $stream = $this->wrapResponseStream($request, $headers, $source);
        if (!array_key_exists('content-encoding', $headers)) {
            return $stream;
        }
        $encodings = $this->splitHeaderValues($headers['content-encoding']);
        foreach ($encodings as $encoding) {
            switch ($encoding) {
                case 'gzip':
                    stream_filter_append($stream, 'zlib.inflate', STREAM_FILTER_READ, ['window' => 16]);
                    break;
                case 'deflate':
                    stream_filter_append($stream, 'zlib.inflate', STREAM_FILTER_READ, ['window' => 15]);
                    break;
            }
        }
        return $stream;
    }

    /**
     * @param RequestInterface $request
     * @param array $headers
     * @param $source
     * @return resource
     */
    private function wrapResponseStream(RequestInterface $request, array $headers, $source)
    {
        if (array_key_exists('content-length', $headers)) {
            return fopen('webclient-simple-response://', 'rb+', false, stream_context_create([
                'webclient-simple-response' => [
                    'source' => $source,
                    'length' => (int)$headers['content-length'][0],
                    'request' => $request,
                ],
            ]));
        }
        if (array_key_exists('transfer-encoding', $headers)) {
            $encodings = $this->splitHeaderValues($headers['transfer-encoding']);
            if (in_array('chunked', $encodings)) {
                return fopen('webclient-chunked-response://', 'rb+', false, stream_context_create([
                    'webclient-chunked-response' => [
                        'source' => $source,
                        'request' => $request,
                    ],
                ]));
            }
        }
        return $source;
    }

    private function splitHeaderValues(array $values): array
    {
        return array_filter(array_map('trim', explode(',', implode(',', $values))));
    }
}
