[![Latest Stable Version](https://img.shields.io/packagist/v/webclient/webclient.svg?style=flat-square)](https://packagist.org/packages/webclient/webclient)
[![Total Downloads](https://img.shields.io/packagist/dt/webclient/webclient.svg?style=flat-square)](https://packagist.org/packages/webclient/webclient/stats)
[![License](https://img.shields.io/packagist/l/webclient/webclient.svg?style=flat-square)](https://github.com/phpwebclient/webclient/blob/master/LICENSE)
[![PHP](https://img.shields.io/packagist/php-v/webclient/webclient.svg?style=flat-square)](https://php.net)

# webclient/webclient

Simple HTTP client without cURL dependency.

# Install

Install this package, your favorite [psr-7 implementation](https://packagist.org/providers/psr/http-message-implementation) and your favorite [psr-17 implementation](https://packagist.org/providers/psr/http-factory-implementation).

```bash
composer require webclient/webclient:^1.0
```

# Using

```php
<?php

use Webclient\Http\Webclient;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/** 
 * @var ResponseFactoryInterface $responseFactory
 * @var StreamFactoryInterface $streamFactory
 * @var float $timeout = 60
 */
$http = new Webclient($responseFactory, $streamFactory, $timeout);

/** @var RequestInterface $request */
$response = $http->sendRequest($request);

$code = $response->getStatusCode();
$phrase = $response->getReasonPhrase();
$headers = $response->getHeaders();
$someHeader = $response->getHeader('Content-Type');

$body = $response->getBody()->__toString();
```

# Extensions

- [Follow redirects](https://packagist.org/packages/webclient/ext-redirect)
- [Adding cookies to request](https://packagist.org/packages/webclient/ext-cookie)
- [Change protocol version](https://packagist.org/packages/webclient/ext-protocol-version)
- [HTTP-Cache for your client](https://packagist.org/packages/webclient/ext-cache)

