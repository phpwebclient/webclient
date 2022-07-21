<?php

namespace Webclient\Http\Exception;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

final class SslConnectionError extends RuntimeException implements NetworkExceptionInterface
{
    private RequestInterface $request;

    public function __construct(RequestInterface $request, $message, Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->request = $request;
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
