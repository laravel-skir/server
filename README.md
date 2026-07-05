# Laravel Skir Server

Laravel package for exposing SkirRPC methods from a Laravel application.

## Installation

```bash
composer require laravel-skir/server
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

Then run generation from your Laravel app:

```bash
php artisan skir:generate-client
```

For every module that contains methods, the generator writes:

```text
app/SkirGenerated/Admin/SkirMethods.php
app/SkirGenerated/Admin/SkirProcedures.php
app/SkirGenerated/Admin/SkirProcedureProvider.php
```

Make sure the output directory is covered by Composer autoloading. For example, map `App\\Skir\\` to `app/SkirGenerated/` or choose an output directory that already matches your app's autoload setup.

`SkirProcedures.php` is the interface your application implements. With `skir-laravel-data-generator`, it looks like this:

```php
namespace App\Skir\Admin;

use LaravelSkir\Server\RequestContext;

interface SkirProcedures
{
    public function getUser(GetUserRequestData $request, RequestContext $context): UserData;
}
```

Implement it in your app code:

```php
namespace App\Skir\Admin;

use App\Models\User as UserModel;
use LaravelSkir\Server\RequestContext;

final class AdminProcedures implements SkirProcedures
{
    public function getUser(GetUserRequestData $request, RequestContext $context): UserData
    {
        $user = UserModel::query()->findOrFail($request->userId);

        return new UserData(
            userId: $user->id,
            name: $user->name,
        );
    }
}
```

Bind the generated interface to your implementation:

```php
use App\Skir\Admin\AdminProcedures;
use App\Skir\Admin\SkirProcedures;

$this->app->bind(SkirProcedures::class, AdminProcedures::class);
```

Register the generated provider on an endpoint:

```php
use App\Skir\Admin\SkirProcedureProvider;
use Illuminate\Support\Facades\Route;

Route::skirRpc('/api/skir', [
    SkirProcedureProvider::class,
]);
```

The generated provider handles the repetitive work:

- Registers every generated `SkirMethods::*()` descriptor with the endpoint.
- Hydrates incoming payloads into generated request DTOs.
- Calls your `SkirProcedures` implementation.
- Converts returned DTOs back into Skir payload arrays.

With `skir-laravel-data-generator`, request hydration uses `makeFromSkirPayload()`, so Laravel Data validation runs before your procedure method is called.

## Standard PHP DTO example

When using `skir-php-generator`, the generated interface uses standard PHP DTOs:

```php
namespace App\Skir\Admin;

use LaravelSkir\Server\RequestContext;

interface SkirProcedures
{
    public function getUser(GetUserRequest $request, RequestContext $context): User;
}
```

Your implementation returns generated DTO objects:

```php
namespace App\Skir\Admin;

use LaravelSkir\Server\RequestContext;

final class AdminProcedures implements SkirProcedures
{
    public function getUser(GetUserRequest $request, RequestContext $context): User
    {
        return new User(
            userId: $request->userId,
            name: 'Maxim',
        );
    }
}
```

## Manual registration

You can still register handlers manually. This is useful for tests, tiny endpoints, or experiments.

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
