<?php

declare(strict_types=1);

namespace Webclient\Http\StreamWrapper;

use InvalidArgumentException;

final class SimpleResponse extends Wrapper
{
    private int $length;

    protected function get_options_key(): string
    {
        return 'webclient-simple-response';
    }

    protected function check_options(array $options, string $key)
    {
        parent::check_options($options, $key);
        $length = $options[$key]['length'] ?? null;
        if (!is_int($length)) {
            throw new InvalidArgumentException(
                sprintf('option %s.length must be an integer', $key)
            );
        }
        $this->length = $length;
    }

    protected function read_to(int $position, bool $return): string
    {
        fseek($this->response, $this->read);
        $chunk = 2048;
        $read = 0;
        $len = $position - $this->read;
        $left = $len;
        $result = '';
        while ($left > 0) {
            $size = $left > $chunk ? $chunk : $len;
            $part = fread($this->source, $size);
            fwrite($this->response, $part);
            $read += strlen($part);
            $left -= $size;
            if ($return) {
                $result .= $part;
            }
        }
        $this->read += $read;
        if ($this->read >= $this->length) {
            fclose($this->source);
            $this->isReady = true;
        }
        return $result;
    }
}
