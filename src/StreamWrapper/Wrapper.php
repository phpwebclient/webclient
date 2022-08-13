<?php

declare(strict_types=1);

namespace Webclient\Http\StreamWrapper;

use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Webclient\Http\Exception\ConnectionTimedOut;

abstract class Wrapper
{
    /** @var resource */
    public $context;

    /** @var resource */
    protected $response;

    /** @var resource */
    protected $source;

    protected bool $isReady = false;
    protected int $read = 0;
    protected int $position = 0;

    private RequestInterface $request;

    abstract protected function readTo(int $position, bool $return): string;

    abstract protected function getOptionsKey(): string;

    public function streamClose()
    {
        if (is_resource($this->response)) {
            fclose($this->response);
        }
        if (is_resource($this->source)) {
            fclose($this->source);
        }
    }

    public function streamEof(): bool
    {
        if (!$this->isReady) {
            return false;
        }
        return $this->position >= $this->read;
    }

    public function streamFlush(): bool
    {
        trigger_error('can not flush the resource');
        return false;
    }

    public function streamLock(int $operation): bool
    {
        trigger_error('can not lock the resource');
        return false;
    }

    public function streamOpen(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $options = stream_context_get_options($this->context);
        $key = $this->getOptionsKey();
        $this->checkOptions($options, $key);
        return is_resource($this->response);
    }

    public function streamRead(int $count): string
    {
        if ($this->streamEof()) {
            return '';
        }
        if ($this->isReady) {
            if ($this->position > $this->read) {
                return '';
            }
            fseek($this->response, $this->position);
            $data = fread($this->response, $count);
            $this->position += strlen($data);
            return $data;
        }

        if ($this->position < $this->read) {
            fseek($this->response, $this->position);
            $exists = fread($this->response, $this->read - $this->position);
            $size = $count - strlen($exists);
        } else {
            fseek($this->response, $this->read);
            $exists = '';
            $size = $count;
        }

        $end = $this->read + $size;
        if ($end <= $this->read) {
            return fread($this->response, $count);
        }

        $this->checkTimeout();
        $data = $exists . $this->readTo($end, true);
        $len = strlen($data);
        $this->position += min($len, $count);
        if ($len > $count) {
            return substr($data, 0, $count);
        }

        return $data;
    }

    public function streamSeek(int $offset, int $whence = SEEK_SET): bool
    {
        switch ($whence) {
            case SEEK_SET:
                $position = $offset;
                break;
            case SEEK_CUR:
                $position = $this->position + $offset;
                break;
            case SEEK_END:
                $position = $this->read + $offset;
                break;
            default:
                throw new InvalidArgumentException();
        }

        if ($position > $this->read) {
            $this->checkTimeout();
            $this->readTo($position, false);
        }
        $this->position = $position;
        return true;
    }

    public function streamSetOption(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }

    /**
     * @return array|false
     */
    public function streamStat()
    {
        return fstat($this->response);
    }

    public function streamTell(): int
    {
        return $this->position;
    }

    public function streamTruncate(int $new_size): bool
    {
        trigger_error('resource is readonly');
        return false;
    }

    public function streamWrite(string $data): int
    {
        trigger_error('resource is readonly');
        return 0;
    }

    private function checkTimeout()
    {
        $metadata = stream_get_meta_data($this->source);
        $timedOut = $metadata['timed_out'] ?? false;
        if ($timedOut) {
            throw new ConnectionTimedOut($this->request, 'connection timed out');
        }
    }

    protected function checkOptions(array $options, string $key)
    {
        $source = $options[$key]['source'] ?? null;
        if (!is_resource($source)) {
            throw new InvalidArgumentException(
                sprintf('option %s.source must be an instance of resource', $key)
            );
        }
        $request = $options[$key]['request'] ?? null;
        if (!$request instanceof RequestInterface) {
            throw new InvalidArgumentException(
                sprintf('option %s.request must be an instance of %s', $key, RequestInterface::class)
            );
        }

        $this->source = $source;
        $this->request = $request;
        $this->response = fopen('php://temp', 'w+');
    }

    public function __call(string $name, array $arguments)
    {
        switch ($name) {
            case 'stream_close':
                return call_user_func_array([$this, 'streamClose'], $arguments);
            case 'stream_eof':
                return call_user_func_array([$this, 'streamEof'], $arguments);
            case 'stream_flush':
                return call_user_func_array([$this, 'streamFlush'], $arguments);
            case 'stream_lock':
                return call_user_func_array([$this, 'streamLock'], $arguments);
            case 'stream_open':
                return call_user_func_array([$this, 'streamOpen'], $arguments);
            case 'stream_read':
                return call_user_func_array([$this, 'streamRead'], $arguments);
            case 'stream_seek':
                return call_user_func_array([$this, 'streamSeek'], $arguments);
            case 'stream_set_option':
                return call_user_func_array([$this, 'streamSetOption'], $arguments);
            case 'stream_stat':
                return call_user_func_array([$this, 'streamStat'], $arguments);
            case 'stream_tell':
                return call_user_func_array([$this, 'streamTell'], $arguments);
            case 'stream_truncate':
                return call_user_func_array([$this, 'streamTruncate'], $arguments);
            case 'stream_write':
                return call_user_func_array([$this, 'streamWrite'], $arguments);
        }
        return false;
    }
}
