# Laravel Skir Server

Laravel package for exposing SkirRPC methods from a Laravel application.

## Installation

```bash
composer require php-skir/server
```

## Generate definitions and a manifest

This package hosts and implements a SkirRPC server. Install the Skir CLI and one PHP generator in the Laravel application. This example uses [the Laravel Data generator](https://github.com/php-skir/skir-laravel-data-generator), which also requires `spatie/laravel-data`:

```bash
npm install --save-dev skir skir-laravel-data-generator
composer require spatie/laravel-data
```

Use [`skir-php-generator`](https://github.com/php-skir/skir-php-generator) instead when standard PHP DTOs are preferred.

```yaml
# skir.yml
generators:
  - mod: skir-laravel-data-generator
    outDir: app/Skir/skirout
    config:
      namespace: Skir
```

```bash
npx skir gen
composer dump-autoload
```

Both generators write `skir-server-manifest.json` at the root of their configured `outDir`. The manifest contains versioned module and method metadata, generated method-enum classes, PHP method names, PHP request and response types, and fully qualified record classes. The default server configuration reads:

```text
app/Skir/skirout/skir-server-manifest.json
```

Make the generated namespace available through Composer. For the example above, map `Skir\\` to `app/Skir/skirout/`, or use the generator's `configure-composer` command documented in its repository.

## Scaffold controllers

Scaffold every method, complete modules, or exact method IDs from the generated manifests:

```bash
php artisan skir:make --all
php artisan skir:make --module=Admin.Users
php artisan skir:make --method=Admin.Users.GetUser
```

Generation is deliberately not run by default. Add `--generate` when the command should first run the configured generator command and reload the resulting manifests:

```bash
php artisan skir:make --generate --module=Admin.Users
```

Choose one of three controller layouts:

```bash
# One controller per selected module; this is the default.
php artisan skir:make --module=Admin.Users --style=module

# One invokable controller per selected method.
php artisan skir:make --method=Admin.Users.GetUser --style=invokable

# One controller for the complete selection.
php artisan skir:make --all --style=single
php artisan skir:make --all --controller='App\Skir\RpcController'
```

`--controller` implies the `single` style. To use a mixture of layouts, run the command once for each selection; layout is an implementation choice and is not stored in the generated schema.

Object request methods can use generated Laravel Form Requests:

```bash
php artisan skir:make --module=Admin.Users --with-requests
php artisan skir:make --module=Admin.Users --without-requests
```

Without either flag, the configured `form_requests` default is used. Scalar and union request parameters remain directly typed because Form Requests apply only to object request methods.

In interactive terminals, the command prompts for the selection, layout, single controller class when applicable, and Form Request preference. It shows the selected methods and controller destinations before asking for confirmation. In non-interactive mode, pass `--all`, `--module`, or `--method` explicitly.

### Configuration

Publish the configuration when the defaults do not fit the application:

```bash
php artisan vendor:publish --tag=skir-server-config
```

```php
<?php

return [
    'manifests' => [
        base_path('app/Skir/skirout/skir-server-manifest.json'),
    ],

    // An argv list, not a shell command string.
    'generator_command' => ['npx', 'skir', 'gen'],

    'scaffolding' => [
        'controller_style' => 'module',
        'controller_namespace' => 'App\\Skir',
        'single_controller' => 'App\\Skir\\SkirController',
        'request_namespace' => 'App\\Http\\Requests\\Skir',
        'form_requests' => true,
    ],
];
```

`generator_command` is passed as an argument array, runs from the Laravel project root, streams its output, and has no process timeout. A failed generator stops scaffolding before manifest loading or application file writes.

### Additive regeneration

Running `skir:make` again adds missing attributed methods to existing module and single controllers. It does not replace existing method definitions or delete methods that no longer appear in the selected manifest; stale attributed methods are reported as warnings. An identical rerun is a no-op.

Planned controller source, method, import, and destination conflicts are checked before publication. Successful publications are journaled and rolled back when that can be done safely. Concurrent filesystem changes or rollback failures are surfaced with the affected paths and recovery details instead of being hidden.

### Controller layouts and route registration

Module and single controllers contain attributed methods and are registered as controller groups:

```php
use App\Skir\Admin\Users\UsersController;
use Illuminate\Support\Facades\Route;
use Skir\Server\Facades\Skir;

Route::skirRpc('/api/skir', [
    Skir::controller(UsersController::class),
]);
```

```php
namespace App\Skir\Admin\Users;

use LogicException;
use Skir\Admin\Users\AdminUsersSkirMethod;
use Skir\Admin\Users\GetUserRequestData;
use Skir\Admin\Users\UserData;
use Skir\Server\Attributes\SkirMethod;
use Skir\Server\SkirContext;

final class UsersController
{
    #[SkirMethod(AdminUsersSkirMethod::GetUser)]
    public function getUser(GetUserRequestData $request, SkirContext $context): UserData
    {
        throw new LogicException('Skir method [Admin.Users.GetUser] is not implemented.');
    }
}
```

The `single` style has the same attributed method shape, but places all selected methods in one configured controller. Invokable controllers contain one `__invoke()` method and are registered for an exact generated enum case:

```php
use App\Skir\Admin\Users\GetUserController;
use Illuminate\Support\Facades\Route;
use Skir\Admin\Users\AdminUsersSkirMethod;
use Skir\Server\Facades\Skir;

Route::skirRpc('/api/skir', [
    Skir::method(AdminUsersSkirMethod::GetUser, GetUserController::class),
]);
```

After scaffolding, the command prints fully qualified route registration hints. Internally these are structured registrations containing the controller class and, for invokable controllers, the method enum class and case; callers integrating the scaffolder directly do not need to parse display strings.

## Validation and request objects

Directly type-hinting a generated standard PHP DTO or Laravel Data object remains supported. The dispatcher hydrates the decoded Skir payload into that object before calling the controller:

```php
public function getUser(GetUserRequestData $request, SkirContext $context): UserData
```

For semantic input validation, a Form Request keeps validation and authorization separate from controller business logic. This follows the same Laravel pattern supported by Sajya, which was an inspiration for the package's server ergonomics.

Generate a Form Request for one exact object-request method:

```bash
php artisan skir:make-request Admin.Users.GetUser
php artisan skir:make-request Admin.Users.GetUser --name=ShowUserRequest
```

Without a method argument, an interactive terminal offers the available object-request methods. The command refuses to overwrite an existing request class. Generated requests extend `Skir\Server\Http\Requests\SkirFormRequest`; their `@extends` template annotation gives `skir()` the generated data-object return type for static analysis:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Skir\Admin\Users;

use Illuminate\Contracts\Validation\ValidationRule;
use Skir\Admin\Users\GetUserRequestData;
use Skir\Server\Http\Requests\SkirFormRequest;

/** @extends SkirFormRequest<GetUserRequestData> */
final class GetUserFormRequest extends SkirFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'userId' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return class-string<GetUserRequestData> */
    protected function skirClass(): string
    {
        return GetUserRequestData::class;
    }
}
```

The generated stub denies authorization by default; change `authorize()` when implementing it. In the controller, use validated request data through `skir()`:

```php
public function getUser(GetUserFormRequest $request, SkirContext $context): UserData
{
    $payload = $request->skir(); // GetUserRequestData
}
```

The Form Request receives only the decoded Skir request payload as its input while retaining Laravel request context such as the user, route, headers, and session. Its validation failures become package-local `skir_validation_failed` responses, and authorization failures become `skir_authorization_failed` responses. Exceptions deliberately thrown later by controller business logic retain Laravel's normal rendering and status behavior.

Form Requests are optional. A controller can instead type-hint the generated object and call Laravel's `Validator` facade explicitly when that better suits the implementation.

## Optional PHP client

The server package does not depend on the [`php-skir/client`](https://github.com/php-skir/client) package. A client will usually be installed in a separate caller project that consumes the hosted RPC service:

```bash
composer require php-skir/client
```

That client repository documents transport setup and its optional `php artisan skir:generate-client` wrapper. Client generation is not part of the server package's main setup flow.

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
