<?php

use Webclient\Http\StreamWrapper\ChunkedResponse;
use Webclient\Http\StreamWrapper\SimpleResponse;

stream_wrapper_register('webclient-simple-response', SimpleResponse::class);
stream_wrapper_register('webclient-chunked-response', ChunkedResponse::class);
