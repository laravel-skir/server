# Laravel Skir Server

Laravel package for exposing SkirRPC methods from a Laravel application.

## Installation

```bash
composer require php-skir/server
```

## Generated server procedures

Start with a Skir method definition:

```skir
// skir-src/admin/users.skir
struct GetUserRequest {
  user_id: int32;
}

struct User {
  user_id: int32;
  name: string;
}

method GetUser(GetUserRequest): User = 3180856469;
```

Configure one of the PHP generators in `skir.yml`:

```yaml
generators:
  - mod: skir-laravel-data-generator
    outDir: app/SkirGenerated
    config:
      namespace: App\Skir
```

Then run the configured generators from the project root:

```bash
npx skir gen
```

### Optional Artisan wrapper

The [`php-skir/client`](https://github.com/php-skir/client) package provides an optional Artisan wrapper for running the configured Skir generators:

```bash
php artisan skir:generate-client
```

The client package is not required to host a SkirRPC server. It will usually be installed in a separate application that consumes the server, so server projects can run `npx skir gen` directly.

For every module that contains methods, the generator writes:

```text
app/SkirGenerated/Admin/SkirMethods.php
app/SkirGenerated/Admin/AdminSkirMethod.php
app/SkirGenerated/Admin/AbstractSkirProcedures.php
app/SkirGenerated/Admin/SkirProcedures.php
app/SkirGenerated/Admin/SkirProcedureProvider.php
```

Make sure the output directory is covered by Composer autoloading. For example, map `App\\Skir\\` to `app/SkirGenerated/` or choose an output directory that already matches your app's autoload setup.

Register a Skir endpoint as one service and compose it from controllers:

```php
use App\Skir\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Skir\Server\Facades\Skir;

Route::skirRpc('/api/skir', [
    Skir::controller(UserController::class),
])->studio();
```

Controller methods are registered only when they have a `SkirMethod` attribute. The PHP method name is not used for Skir method resolution; the generated enum case is the source of truth.

```php
namespace App\Skir\Controllers;

use App\Models\User as UserModel;
use App\Skir\Admin\AdminSkirMethod;
use App\Skir\Admin\GetUserRequestData;
use App\Skir\Admin\UserData;
use Skir\Server\Attributes\SkirMethod;
use Skir\Server\SkirContext;

final class UserController
{
    #[SkirMethod(AdminSkirMethod::GetUser)]
    public function get(GetUserRequestData $request, SkirContext $context): UserData
    {
        $user = UserModel::query()->findOrFail($request->userId);

        return new UserData(
            userId: $user->id,
            name: $user->name,
        );
    }
}
```

The controller dispatcher handles the repetitive work:

- Registers every generated `SkirMethods::*()` descriptor with the endpoint.
- Hydrates incoming payloads into generated request DTOs.
- Calls your attributed controller methods.
- Converts returned DTOs back into Skir payload arrays.

With `skir-laravel-data-generator`, request hydration uses `makeFromSkirPayload()`, so Laravel Data validation runs before your procedure method is called.

For one-method controllers, register an invokable controller explicitly:

```php
use App\Skir\Admin\AdminSkirMethod;
use App\Skir\Controllers\GetUserController;
use Illuminate\Support\Facades\Route;
use Skir\Server\Facades\Skir;

Route::skirRpc('/api/skir', [
    Skir::method(AdminSkirMethod::GetUser, GetUserController::class),
]);
```

## Standard PHP DTO example

When using `skir-php-generator`, controller methods use standard PHP DTOs:

```php
namespace App\Skir\Controllers;

use App\Skir\Admin\AdminSkirMethod;
use App\Skir\Admin\GetUserRequest;
use App\Skir\Admin\User;
use Skir\Server\Attributes\SkirMethod;
use Skir\Server\SkirContext;

final class UserController
{
    #[SkirMethod(AdminSkirMethod::GetUser)]
    public function get(GetUserRequest $request, SkirContext $context): User
    {
        return new User(
            userId: $request->userId,
            name: 'Maxim',
        );
    }
}
```

## Compatibility APIs

The lower-level provider APIs remain available for manual adapters and backwards compatibility:

```php
use App\Skir\Admin\AdminProcedures;
use App\Skir\Admin\SkirProcedureProvider;
use App\Skir\Admin\SkirProcedures;
use Illuminate\Support\Facades\Route;

$this->app->bind(SkirProcedures::class, AdminProcedures::class);

Route::skirRpc('/api/skir', [
    SkirProcedureProvider::class,
]);
```

`RequestContext` is still accepted as a compatibility type. New code should type-hint `SkirContext`.

## Manual registration

You can still register handlers manually. This is useful for tests, tiny endpoints, or experiments.

Register an endpoint in your routes file:

```php
use Illuminate\Support\Facades\Route;

Route::skirRpc('/api/skir');
```

Register generated Skir method descriptors with handlers:

```php
use Skir\Runtime\MethodDescriptor;
use Skir\Runtime\Type;
use Skir\Server\SkirContext;
use Skir\Server\SkirServer;

app(SkirServer::class)->addMethod(
    new MethodDescriptor('Square', 1001, Type::float32(), Type::float32()),
    fn (float $value, SkirContext $context): float => $value * $value,
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

## Studio

Studio is disabled by default. Enable it per endpoint:

```php
Route::skirRpc('/api/skir', [
    Skir::controller(UserController::class),
])->studio();
```

Then open `/api/skir?studio` in a browser. Studio renders the methods registered on that endpoint only, so separate Skir endpoints keep separate procedure lists.

## Codecs

Dense JSON is the default endpoint codec:

```php
Route::skirRpc('/api/skir', [
    Skir::controller(UserController::class),
]);
```

You can choose a codec per endpoint:

```php
use Skir\Server\Codecs\SkirCodecs;

Route::skirRpc('/api/skir-readable', [
    Skir::controller(UserController::class),
], SkirCodecs::standardJson());

Route::skirRpc('/api/skir-base64', [
    Skir::controller(UserController::class),
], SkirCodecs::base64DenseJson());

Route::skirRpc('/api/skir-cbor', [
    Skir::controller(UserController::class),
], SkirCodecs::cbor());
```

- `denseJson()` decodes and encodes Skir dense JSON values. This is the default for production APIs.
- `standardJson()` passes decoded JSON values through unchanged. Use it when you want readable JSON at the HTTP boundary and your procedures/generated providers handle that shape.
- `base64DenseJson()` accepts and returns base64-encoded dense JSON strings inside the JSON envelope.
- `cbor()` accepts an `application/cbor` request body with `method` and dense `request` values, and returns an `application/cbor` response body.

CBOR support is optional. Install `spomky-labs/cbor-php` in the consuming app before using `SkirCodecs::cbor()`.
