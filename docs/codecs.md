# Codecs

A codec controls how SkirRPC endpoints decode request values and encode response values. Configure a package-wide default or select a codec per route with the third argument to `Route::skirRpc()`.

## Default codec

The shipped configuration uses dense JSON. To use standard JSON as the package-wide default, publish the configuration and set `codec` to `StandardJsonCodec::class`:

```php
<?php

declare(strict_types=1);

use Skir\Server\Codecs\StandardJsonCodec;

return [
    'studio_enabled' => env('SKIR_SERVER_STUDIO_ENABLED', false),
    'studio_query_key' => env('SKIR_SERVER_STUDIO_QUERY_KEY', 'studio'),
    'codec' => StandardJsonCodec::class,
];
```

The codec class is resolved through Laravel's container and must implement `Skir\Server\Codecs\SkirCodec`. The built-in codec classes are `DenseJsonCodec`, `StandardJsonCodec`, `Base64DenseJsonCodec`, and `CborCodec`, all in the `Skir\Server\Codecs` namespace. An explicit codec passed as the third argument to `Route::skirRpc()` overrides the configured default for that route.

## Dense JSON

Dense JSON uses the generated method descriptor to decode and encode Skir's compact JSON representation:

```php
use App\Http\Skir\UserController;
use Illuminate\Support\Facades\Route;
use Skir\Server\Facades\Skir;

Route::skirRpc('/api/skir', [
    Skir::controller(UserController::class),
]);
```

The explicit equivalent is:

```php
use Skir\Server\Codecs\SkirCodecs;

Route::skirRpc('/api/skir', [
    Skir::controller(UserController::class),
], SkirCodecs::denseJson());
```

## Standard JSON

Standard JSON passes the decoded JSON request value and response value through without dense conversion. Use it when the HTTP boundary should remain human-readable and the registered procedures handle that shape:

```php
use Skir\Server\Codecs\SkirCodecs;

Route::skirRpc('/api/skir-readable', [
    Skir::controller(UserController::class),
], SkirCodecs::standardJson());
```

## Base64 dense JSON

Base64 dense JSON accepts a base64-encoded dense JSON string in the envelope's `request` field and returns the response in the same form:

```php
use Skir\Server\Codecs\SkirCodecs;

Route::skirRpc('/api/skir-base64', [
    Skir::controller(UserController::class),
], SkirCodecs::base64DenseJson());
```

The outer SkirRPC envelope remains JSON; only the request and response values are represented as base64-encoded dense JSON.

## CBOR

CBOR accepts an `application/cbor` request body containing the SkirRPC `method` and dense `request` values, then returns an `application/cbor` response body:

```php
use Skir\Server\Codecs\SkirCodecs;

Route::skirRpc('/api/skir-cbor', [
    Skir::controller(UserController::class),
], SkirCodecs::cbor());
```

CBOR support is optional. Install its runtime dependency in the consuming Laravel application before selecting this codec:

```bash
composer require spomky-labs/cbor-php
```

Calling `SkirCodecs::cbor()` without the dependency throws a `SkirServerException` while the route is being built.
