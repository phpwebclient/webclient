<?php

declare(strict_types=1);

namespace Webclient\Http\StreamWrapper;

final class ChunkedResponse extends Wrapper
{
    protected function get_options_key(): string
    {
        return 'webclient-chunked-response';
    }

    protected function read_to(int $position, bool $return): string
    {
        $result = '';
        fseek($this->response, $this->read);
        while (!$this->isReady && $this->read < $position) {
            $part = $this->read_chunk();
            $len = strlen($part);
            if ($return) {
                $result .= $part;
            }
            if ($len === 0) {
                fclose($this->source);
                $this->isReady = true;
            } else {
                $this->read += $len;
                fwrite($this->response, $part);
            }
        }
        return $result;
    }

    private function read_chunk(): string
    {
        $data = '  ';
        while (substr($data, -2) !== "\r\n") {
            $data .= fread($this->source, 1);
        }
        $hex = substr($data, 2, -2);
        $chunk = (int)hexdec($hex);
        if ($chunk === 0) {
            fread($this->source, 2);
            return '';
        }
        return fread($this->source, $chunk);
    }
}
