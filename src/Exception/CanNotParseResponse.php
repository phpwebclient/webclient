<?php

declare(strict_types=1);

namespace Webclient\Http\Exception;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

final class CanNotParseResponse extends RuntimeException implements ClientExceptionInterface
{
}
