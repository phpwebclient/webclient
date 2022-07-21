<?php

declare(strict_types=1);

namespace Webclient\Http\StreamWrapper;

final class ChunkedResponse extends Wrapper
{
    protected function getOptionsKey(): string
    {
        return 'webclichn';
    }

    protected function readTo(int $position, bool $return): string
    {
        $result = '';
        fseek($this->response, $this->read);
        while (!$this->isReady && $this->read < $position) {
            $part = $this->readChunk();
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

    private function readChunk(): string
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
