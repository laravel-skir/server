# Codecs

A codec controls how a SkirRPC endpoint decodes request values and encodes response values. Choose it per endpoint with the third argument to `Route::skirRpc()`.

## Dense JSON

Dense JSON is the default. It uses the generated method descriptor to decode and encode Skir's compact JSON representation:

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
