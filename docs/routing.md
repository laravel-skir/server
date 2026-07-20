# Routing

A SkirRPC endpoint is one Laravel route composed from one or more procedure sources. Attribute-based controllers are the primary Laravel-facing workflow, while invokable controllers, generated providers, and manual handlers remain available for other layouts.

## Attributed controllers

Register a controller through `Skir::controller()`:

```php
use App\Skir\Admin\AdminController;
use Illuminate\Support\Facades\Route;
use Skir\Server\Facades\Skir;

Route::skirRpc('/api/skir', [
    Skir::controller(AdminController::class),
]);
```

Only public, non-static methods carrying a `SkirMethod` attribute are registered:

```php
<?php

declare(strict_types=1);

namespace App\Skir\Admin;

use Skir\Admin\AdminSkirMethod;
use Skir\Admin\GetUserRequestData;
use Skir\Admin\UserData;
use Skir\Server\Attributes\SkirMethod;
use Skir\Server\SkirContext;

final class AdminController
{
    #[SkirMethod(AdminSkirMethod::GetUser)]
    public function get(GetUserRequestData $request, SkirContext $context): UserData
    {
        return new UserData(
            userId: $request->userId,
            name: 'Maxim',
        );
    }
}
```

The generated enum case resolves to the matching `SkirMethods` descriptor. The enum—not the PHP method name—is the source of truth for Skir method identity.

For every attributed method, the dispatcher:

1. Registers its generated method descriptor with the endpoint.
2. Builds method-specific Laravel route metadata and discovers controller middleware.
3. Places the decoded Skir payload in a method-scoped Laravel request.
4. Resolves Form Requests, generated DTOs, route parameters, contextual attributes, `SkirContext`, and other dependencies through Laravel's controller dispatcher.
5. Invokes the controller through `callAction()` when available.
6. Converts the result to a Skir payload, encodes the response, and lets controller middleware inspect or replace that encoded response.

Simple Data Objects and Laravel Data hydrate through `makeFromSkirPayload()`. Standard PHP objects hydrate through `fromArray()`. See [Generated procedures](generated-procedures.md#request-hydration-and-validation) for their validation differences.

## Controller middleware and authorization

Laravel controller attributes can protect individual Skir procedures on a shared endpoint. In this example `Login` remains public, while `GetMe` passes through Sanctum bearer-token authentication:

```php
<?php

declare(strict_types=1);

namespace App\Skir\Account;

use App\Http\Requests\Skir\Account\LoginFormRequest;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Skir\Account\AccountSkirMethod;
use Skir\Account\LoginResponseData;
use Skir\Account\UserData;
use Skir\Server\Attributes\SkirMethod;
use Skir\Server\SkirContext;

final class AccountController
{
    #[SkirMethod(AccountSkirMethod::Login)]
    public function login(LoginFormRequest $request): LoginResponseData
    {
        // Public method.
    }

    #[SkirMethod(AccountSkirMethod::GetMe)]
    #[Middleware('auth:sanctum')]
    public function getMe(SkirContext $context): UserData
    {
        // Protected method on the same endpoint.
    }
}
```

Registering that controller and enabling Studio still uses one endpoint:

```php
use App\Skir\Account\AccountController;
use Illuminate\Support\Facades\Route;
use Skir\Server\Facades\Skir;

Route::skirRpc('/api/skir', [
    Skir::controller(AccountController::class),
])->studio();
```

The package uses Laravel's controller middleware discovery, filtering, alias resolution, and pipeline. The supported controller APIs include:

- `#[Middleware(...)]` and `#[Authorize(...)]` from `Illuminate\Routing\Attributes\Controllers`, at class or method level;
- the `Illuminate\Routing\Controllers\HasMiddleware` interface, including `only` and `except` filters;
- middleware registered through Laravel's base `Controller::middleware()` API.

`#[Authorize]` uses Laravel's normal gate middleware, so its ability and model arguments follow Laravel's controller-attribute conventions. Middleware can read the authenticated user and route state, short-circuit the procedure, and inspect or replace the final encoded Symfony response.

Controller middleware belongs to controller-backed procedures. Generated procedure providers and manually registered handlers do not discover or run controller middleware. Put middleware on the outer `Route::skirRpc()` route when it must cover every routing style or the entire endpoint.

### Studio and method middleware

Studio remains endpoint-visible when it is enabled: a GET request such as `/api/skir?studio` renders before a Skir method is selected, so `GetMe`'s `auth:sanctum` controller middleware does not protect the Studio page. Middleware attached to the outer Laravel route still applies to Studio and to every RPC call. Use outer route middleware when Studio itself must be private.

### Middleware limitation

Terminable controller middleware is not supported. A Skir method does not pass through Laravel's HTTP kernel termination phase, so the package rejects controller middleware that defines `terminate()` instead of silently skipping its termination work. Non-terminable middleware follows the normal Laravel pipeline.

## Form Requests and decoded input

Both ordinary Laravel `FormRequest` classes and `SkirFormRequest` subclasses use Laravel's normal container resolution, authorization, preparation, rules, after-validation hooks, and failure behavior. A `SkirFormRequest` additionally provides the typed `$request->skir()` method described in [Generated procedures](generated-procedures.md#request-hydration-and-validation).

Before controller middleware and Form Requests run, the server creates a method-scoped clone of the transport request. Its input bags contain only the decoded Skir method payload—not the outer RPC envelope or transport query parameters. JSON calls receive the payload in the JSON bag, GET and HEAD calls in the query bag, and other calls in the request bag. The other input bags and uploaded files are cleared.

Headers, cookies, route state, session, authentication resolvers, and raw transport content remain available on the scoped request. The original container request is restored after dispatch, including when middleware, validation, authorization, hydration, or controller code throws.

Object payloads can be consumed by one direct request parameter: a Form Request, a supported generated DTO, or an untyped/builtin payload parameter. Multiple direct payload parameters are ambiguous and are rejected before invocation. Object unions and intersections are also rejected; only unions made entirely from builtin types can receive a decoded payload. Route parameters, contextual attributes, `SkirContext`, and container dependencies do not count as direct payload parameters.

### Compose multiple controllers

An endpoint may combine multiple controllers or routing styles:

```php
use App\Skir\Admin\AdminController;
use App\Skir\Admin\GetUserController;
use App\Skir\Health\HealthController;
use Illuminate\Support\Facades\Route;
use Skir\Admin\AdminSkirMethod;
use Skir\Server\Facades\Skir;

Route::skirRpc('/api/skir', [
    Skir::controller(AdminController::class),
    Skir::controller(HealthController::class),
    Skir::method(AdminSkirMethod::GetUser, GetUserController::class),
]);
```

Each Skir method can be registered only once on an endpoint. Duplicate registrations throw a `SkirServerException` while the route is being built.

## Invokable controllers

For a controller dedicated to one method, map the generated method enum directly to its `__invoke()` method:

```php
use App\Skir\Admin\GetUserController;
use Illuminate\Support\Facades\Route;
use Skir\Admin\AdminSkirMethod;
use Skir\Server\Facades\Skir;

Route::skirRpc('/api/skir', [
    Skir::method(AdminSkirMethod::GetUser, GetUserController::class),
]);
```

The invokable method uses the same typed request, `SkirContext`, and response conventions as an attributed method, but does not require a `SkirMethod` attribute:

```php
<?php

declare(strict_types=1);

namespace App\Skir\Admin;

use Skir\Admin\GetUserRequestData;
use Skir\Admin\UserData;
use Skir\Server\SkirContext;

final class GetUserController
{
    public function __invoke(GetUserRequestData $request, SkirContext $context): UserData
    {
        return new UserData(
            userId: $request->userId,
            name: 'Maxim',
        );
    }
}
```

Use attributed controllers to group related methods. Use invokable controllers when each procedure should have its own class. Both layouts can coexist on the same endpoint.

## Generated procedure providers

The generated provider APIs remain available for applications that prefer implementing a module-level contract.

Implement the generated `SkirProcedures` interface in handwritten application code:

```php
<?php

declare(strict_types=1);

namespace App\Skir;

use Skir\Admin\GetUserRequestData;
use Skir\Admin\SkirProcedures;
use Skir\Admin\UserData;
use Skir\Server\SkirContext;

final class AdminProcedures implements SkirProcedures
{
    public function getUser(GetUserRequestData $request, SkirContext $context): UserData
    {
        return new UserData(
            userId: $request->userId,
            name: 'Maxim',
        );
    }
}
```

Bind the implementation in a service provider:

```php
use App\Skir\AdminProcedures;
use Skir\Admin\SkirProcedures;

$this->app->bind(SkirProcedures::class, AdminProcedures::class);
```

Then register the generated provider in the routes file:

```php
use Illuminate\Support\Facades\Route;
use Skir\Admin\SkirProcedureProvider;

Route::skirRpc('/api/skir', [
    SkirProcedureProvider::class,
]);
```

As an inheritance-based alternative, a handwritten class may extend the generated `AbstractSkirProcedures`. That base class is itself a procedure provider, so the handwritten class can be registered directly on the route.

`RequestContext` remains accepted as a compatibility type in controller methods and manually registered handlers. New code should type-hint `SkirContext`. Generated procedure contracts use `SkirContext` explicitly.

## Manual registration

Manual handlers are useful for tests, very small endpoints, or experiments that do not need generated provider classes.

Register an endpoint without route-specific providers:

```php
use Illuminate\Support\Facades\Route;

Route::skirRpc('/api/skir');
```

Then register a descriptor and handler on the application-wide server:

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

The endpoint accepts SkirRPC envelopes. With the default dense JSON codec:

```json
{"method":"Square","request":5.0}
```

The response is the raw encoded Skir value:

```json
25
```

GET requests are also supported. Pass the method and a JSON-encoded request value as query parameters:

```text
/api/skir?method=Square&request=5.0
```

## Studio

Studio is controlled by these settings in `config/skir-server.php`:

```php
'studio_enabled' => env('SKIR_SERVER_STUDIO_ENABLED', false),
'studio_query_key' => env('SKIR_SERVER_STUDIO_QUERY_KEY', 'studio'),
```

Studio is disabled by default. Enable it on an individual endpoint with `studio()`:

```php
use App\Skir\Admin\AdminController;
use Illuminate\Support\Facades\Route;
use Skir\Server\Facades\Skir;

Route::skirRpc('/api/skir', [
    Skir::controller(AdminController::class),
])->studio();
```

Calling `studio()` explicitly enables Studio for that endpoint, regardless of `studio_enabled`. With the default query key, open `/api/skir?studio` with a GET request. Only the query parameter's presence matters; its value is ignored. Studio renders only the procedures registered on that route, so separate endpoints keep separate procedure lists.

Set `studio_enabled` to `true` to enable Studio for every SkirRPC route by default. Calling `studio(false)` on a route overrides that global setting and keeps Studio disabled for that endpoint.

Use `studio_query_key` to change the query parameter. For example, setting it to `skir-studio` makes the Studio URL `/api/skir?skir-studio`.

In production, protect Studio with the route's normal middleware and authorization. The query key only selects the Studio response; it is not access control.
