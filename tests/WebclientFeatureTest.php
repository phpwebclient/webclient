<?php

declare(strict_types=1);

namespace Tests\Webclient\Http;

use Http\Client\Tests\HttpFeatureTest;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Webclient\Extension\Redirect\RedirectClientDecorator;
use Webclient\Http\Webclient;

class WebclientFeatureTest extends HttpFeatureTest
{
    protected function createClient(): ClientInterface
    {
        $factory = new Psr17Factory();
        return new RedirectClientDecorator(
            new Webclient($factory, $factory)
        );
    }
}
