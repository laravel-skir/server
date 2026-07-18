# Routing

A SkirRPC endpoint is one Laravel route composed from one or more procedure sources. Attribute-based controllers are the primary Laravel-facing workflow, while invokable controllers, generated providers, and manual handlers remain available for other layouts.

## Attributed controllers

Register a controller through `Skir::controller()`:

```php
use App\Http\Skir\UserController;
use Illuminate\Support\Facades\Route;
use Skir\Server\Facades\Skir;

Route::skirRpc('/api/skir', [
    Skir::controller(UserController::class),
]);
```

Only public, non-static methods carrying a `SkirMethod` attribute are registered:

```php
<?php

declare(strict_types=1);

namespace App\Http\Skir;

use Skir\Admin\AdminSkirMethod;
use Skir\Admin\GetUserRequestData;
use Skir\Admin\UserData;
use Skir\Server\Attributes\SkirMethod;
use Skir\Server\SkirContext;

final class UserController
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
2. Resolves the controller through Laravel's container.
3. Hydrates a generated request object when the parameter type supports it.
4. Injects `SkirContext` when requested.
5. Invokes the controller method.
6. Converts a generated response object back to a Skir payload.

The Laravel Data generator hydrates struct requests through `makeFromSkirPayload()`, so Laravel Data validation runs before the controller method. The standard PHP generator hydrates structs through `fromArray()`.

### Compose multiple controllers

An endpoint may combine multiple controllers or routing styles:

```php
use App\Http\Skir\AdminController;
use App\Http\Skir\HealthController;
use App\Http\Skir\GetUserController;
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
use App\Http\Skir\GetUserController;
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

namespace App\Http\Skir;

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

Studio is disabled by default. Enable it on an individual endpoint with `studio()`:

```php
use App\Http\Skir\UserController;
use Illuminate\Support\Facades\Route;
use Skir\Server\Facades\Skir;

Route::skirRpc('/api/skir', [
    Skir::controller(UserController::class),
])->studio();
```

Open `/api/skir?studio` with a GET request. Studio renders only the procedures registered on that route, so separate endpoints keep separate procedure lists.

Calling `studio(false)` explicitly keeps Studio disabled. In production applications, apply the application's normal routing middleware and authorization strategy to control access to enabled Studio routes.
