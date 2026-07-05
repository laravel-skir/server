# Laravel Skir Server

Laravel package for exposing SkirRPC methods from a Laravel application.

## Installation

```bash
composer require laravel-skir/server
```

## Usage

Register an endpoint in your routes file:

```php
use Illuminate\Support\Facades\Route;

Route::skirRpc('/api/skir');
```

For generated procedure providers:

```php
use App\Skir\Admin\SkirProcedureProvider;
use Illuminate\Support\Facades\Route;

Route::skirRpc('/api/skir', [
    SkirProcedureProvider::class,
]);
```

Register generated Skir method descriptors with handlers:

```php
use LaravelSkir\Runtime\MethodDescriptor;
use LaravelSkir\Runtime\Type;
use LaravelSkir\Server\RequestContext;
use LaravelSkir\Server\SkirServer;

app(SkirServer::class)->addMethod(
    new MethodDescriptor('Square', 1001, Type::float32(), Type::float32()),
    fn (float $value, RequestContext $context): float => $value * $value,
);
```

Generated providers expect an implementation of the generated `SkirProcedures` interface to be bound in Laravel's container.

```php
use App\Skir\Admin\SkirProcedures;
use App\Skir\AdminProcedures;

$this->app->bind(SkirProcedures::class, AdminProcedures::class);
```

The endpoint accepts SkirRPC request envelopes:

```json
{"method":"Square","request":5.0}
```

Responses are returned as raw Skir dense JSON values:

```json
25
```

GET requests are also supported by passing `method` and a JSON-encoded `request` query parameter.

## Codecs

Dense JSON is the default endpoint codec:

```php
Route::skirRpc('/api/skir', [
    SkirProcedureProvider::class,
]);
```

You can choose a codec per endpoint:

```php
use LaravelSkir\Server\Codecs\SkirCodecs;

Route::skirRpc('/api/skir-readable', [
    SkirProcedureProvider::class,
], SkirCodecs::standardJson());

Route::skirRpc('/api/skir-base64', [
    SkirProcedureProvider::class,
], SkirCodecs::base64DenseJson());
```

- `denseJson()` decodes and encodes Skir dense JSON values. This is the default for production APIs.
- `standardJson()` passes decoded JSON values through unchanged. Use it when you want readable JSON at the HTTP boundary and your procedures/generated providers handle that shape.
- `base64DenseJson()` accepts and returns base64-encoded dense JSON strings inside the JSON envelope.

True Skir binary transport belongs in the runtime package first; this package currently exposes the text-safe base64 dense JSON mode.
