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

    abstract protected function read_to(int $position, bool $return): string;

    abstract protected function get_options_key(): string;

    public function stream_close()
    {
        if (is_resource($this->response)) {
            fclose($this->response);
        }
        if (is_resource($this->source)) {
            fclose($this->source);
        }
    }

    public function stream_eof(): bool
    {
        if (!$this->isReady) {
            return false;
        }
        return $this->position >= $this->read;
    }

    public function stream_flush(): bool
    {
        trigger_error('can not flush the resource');
        return false;
    }

    public function stream_lock(int $operation): bool
    {
        trigger_error('can not lock the resource');
        return false;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $options = stream_context_get_options($this->context);
        $key = $this->get_options_key();
        $this->check_options($options, $key);
        return is_resource($this->response);
    }

    public function stream_read(int $count): string
    {
        if ($this->stream_eof()) {
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

        $this->check_timeout();
        $data = $exists . $this->read_to($end, true);
        $len = strlen($data);
        $this->position += min($len, $count);
        if ($len > $count) {
            return substr($data, 0, $count);
        }

        return $data;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
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
            $this->check_timeout();
            $this->read_to($position, false);
        }
        $this->position = $position;
        return true;
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }

    /**
     * @return array|false
     */
    public function stream_stat()
    {
        return fstat($this->response);
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_truncate(int $new_size): bool
    {
        trigger_error('resource is readonly');
        return false;
    }

    public function stream_write(string $data): int
    {
        trigger_error('resource is readonly');
        return 0;
    }

    public function __destruct()
    {
    }

    private function check_timeout()
    {
        $metadata = stream_get_meta_data($this->source);
        $timedOut = $metadata['timed_out'] ?? false;
        if ($timedOut) {
            throw new ConnectionTimedOut($this->request, 'connection timed out');
        }
    }

    protected function check_options(array $options, string $key)
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
        $this->response = fopen('php://temp', 'rw+');
    }
}
