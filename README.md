![Laravel Skir Server](art/banner.png)

# Laravel Skir Server

[![Tests](https://github.com/php-skir/server/actions/workflows/tests.yml/badge.svg)](https://github.com/php-skir/server/actions/workflows/tests.yml)
[![Coverage](https://raw.githubusercontent.com/php-skir/server/badges/coverage.svg)](https://github.com/php-skir/server/actions/workflows/tests.yml)
[![Composer](https://img.shields.io/packagist/v/php-skir/server?label=composer&logo=composer)](https://packagist.org/packages/php-skir/server)
[![PHP](https://img.shields.io/badge/PHP-%5E8.4-777BB4?logo=php&logoColor=white)](https://packagist.org/packages/php-skir/server)
[![License](https://img.shields.io/github/license/php-skir/server)](LICENSE)

Laravel package for exposing SkirRPC methods from a Laravel application.

## Why Skir?

[Skir](https://skir.build/) is a modern schema language for defining data models and APIs. You describe your structs, enums, and RPC methods once in `.skir` files, then generate clean, idiomatic, and type-safe code across your stack. Skir includes generators for TypeScript, Python, Java, Go, C#, C++, Kotlin, Rust, Swift, and more, while the PHP generators in this ecosystem bring the same schema-first workflow to Laravel. This gives your backend, frontend, and other services a shared source of truth instead of separately maintained DTOs and API contracts.

SkirRPC offers many of the same benefits as gRPC—shared method definitions, generated clients and servers, and end-to-end type safety—but runs over standard HTTP requests and integrates with the frameworks you already use. This package brings that workflow to Laravel: generated contracts handle serialization and type information, while your procedures remain ordinary Laravel controllers resolved through the container.

## Features

- Generate typed server contracts with Simple Data Objects, Laravel Data, or standard PHP objects. See [Generated procedures](docs/generated-procedures.md).
- Scaffold application-owned controllers and Form Requests from generated manifests. See [Scaffolding](docs/scaffolding.md).
- Route procedures to attributed or invokable Laravel controllers with native dependency injection, middleware, authorization, and Form Requests. See [Routing](docs/routing.md).
- Keep generated providers and manual handlers available for non-controller layouts. See [Routing](docs/routing.md#generated-procedure-providers).
- Inspect an endpoint's procedures through its opt-in Studio. See [Studio](docs/routing.md#studio).
- Configure dense JSON, standard JSON, base64 dense JSON, or CBOR package-wide or per endpoint. See [Codecs](docs/codecs.md).

## Quick start

Install the server package and one PHP generator. This example uses Simple Data Objects:

```bash
composer require php-skir/server std-out/simple-data-objects
npm install --save-dev skir skir-simple-data-objects-generator
```

The package defaults work without publishing configuration. To customize them, publish `config/skir-server.php`:

```bash
php artisan vendor:publish --tag=skir-server-config
```

The published file contains:

```php
<?php

declare(strict_types=1);

use Skir\Server\Codecs\DenseJsonCodec;

return [
    'studio_enabled' => env('SKIR_SERVER_STUDIO_ENABLED', false),
    'studio_query_key' => env('SKIR_SERVER_STUDIO_QUERY_KEY', 'studio'),
    'codec' => DenseJsonCodec::class,

    'manifests' => [
        base_path('app/Skir/skirout/skir-server-manifest.json'),
    ],

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

See [Scaffolding](docs/scaffolding.md) for manifest and controller-generation settings. Calling `studio()` or `studio(false)` on a route overrides `studio_enabled`, while an explicit codec passed to `Route::skirRpc()` overrides `codec`.

Define a Skir method:

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

Configure the generator in `skir.yml`:

```yaml
generators:
  - mod: skir-simple-data-objects-generator
    outDir: app/Skir/skirout
    config:
      namespace: Skir
```

Skir requires every output directory to end in `/skirout`. It owns that directory and may replace or remove files inside it, so keep handwritten application code elsewhere.

Generate the PHP contracts, configure Composer, and refresh the autoloader:

```bash
npx skir gen
npx skir-simple-data-objects-generator configure-composer
composer dump-autoload
```

The `configure-composer` command reads `skir.yml` and adds this PSR-4 mapping to `composer.json` when it is missing:

```json
{
  "autoload": {
    "psr-4": {
      "Skir\\": "app/Skir/skirout/"
    }
  }
}
```

Keep controllers outside `skirout`; the default application-owned namespace is `App\Skir`. Scaffold every generated method and its Form Request with:

```bash
php artisan skir:make --all
```

Generated Form Requests deny access by default; define their `authorize()` and `rules()` methods before exposing the procedure. The command prints the route registrations to add. A module controller has this shape:

```php
<?php

declare(strict_types=1);

namespace App\Skir\Admin;

use App\Http\Requests\Skir\Admin\GetUserFormRequest;
use App\Models\User as UserModel;
use Skir\Admin\AdminSkirMethod;
use Skir\Admin\UserData;
use Skir\Server\Attributes\SkirMethod;

final class AdminController
{
    #[SkirMethod(AdminSkirMethod::GetUser)]
    public function getUser(GetUserFormRequest $request): UserData
    {
        $input = $request->skir();
        $user = UserModel::query()->findOrFail($input->userId);

        return new UserData(
            userId: $user->id,
            name: $user->name,
        );
    }
}
```

Register the controller on a SkirRPC endpoint:

```php
use App\Skir\Admin\AdminController;
use Illuminate\Support\Facades\Route;
use Skir\Server\Facades\Skir;

Route::skirRpc('/api/skir', [
    Skir::controller(AdminController::class),
])->studio();
```

Only public controller methods carrying a `SkirMethod` attribute are registered. The generated enum case identifies the Skir method; the PHP method name does not.

The `studio()` call explicitly enables the endpoint-scoped Studio regardless of the configured default. With the default query key, Studio is available at `/api/skir?studio`. See [Routing and Studio](docs/routing.md#studio) for middleware, authorization, and alternative routing layouts.

## Optional client package

The [`php-skir/client`](https://github.com/php-skir/client) package is not required to host a SkirRPC server. It normally belongs in the application consuming this endpoint.
