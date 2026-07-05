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

The endpoint accepts SkirRPC request envelopes:

```json
{"method":"Square","request":5.0}
```

Responses are returned as raw Skir dense JSON values:

```json
25
```

GET requests are also supported by passing `method` and a JSON-encoded `request` query parameter.
