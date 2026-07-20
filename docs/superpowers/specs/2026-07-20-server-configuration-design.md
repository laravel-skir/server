# Server Package Configuration Design

Date: 2026-07-20

## Goal

Add a publishable Laravel configuration file for package-wide Skir server defaults. The configuration controls whether Studio is enabled by default, which query-string key opens Studio, and which codec routes use by default. Existing route-level overrides remain authoritative.

## Public configuration

The package adds `config/skir-server.php`:

```php
<?php

declare(strict_types=1);

use Skir\Server\Codecs\DenseJsonCodec;

return [
    'studio_enabled' => env('SKIR_SERVER_STUDIO_ENABLED', false),
    'studio_query_key' => env('SKIR_SERVER_STUDIO_QUERY_KEY', 'studio'),
    'codec' => DenseJsonCodec::class,
];
```

The codec setting is a class name rather than a short driver name. Laravel resolves it through the service container, allowing applications to select a built-in codec or provide their own `SkirCodec` implementation without extending a package-maintained alias list. The codec is configured in PHP rather than through an environment variable because fully qualified PHP class names are application code, not deployment-specific scalar values.

The service provider merges the package defaults under the `skir-server` key and publishes the file to the consuming application's `config/skir-server.php` using the `skir-server-config` tag.

## Runtime behavior and precedence

`Route::skirRpc()` applies the configured Studio settings to every route it creates:

- `studio_enabled` defaults to `false`. When `true`, a route serves Studio without requiring `->studio()`.
- `studio_query_key` defaults to `studio`. Studio is requested by the presence of that query parameter; its value is irrelevant.
- `->studio()` explicitly enables Studio for one route.
- `->studio(false)` explicitly disables Studio for one route, including when the package-wide default is enabled.

The configured codec class is resolved through Laravel's container and bound as the default `SkirCodec`. It therefore applies both to the application-wide `SkirServer` and to isolated route servers created for route-specific providers. Passing a codec as the third argument to `Route::skirRpc()` remains the highest-precedence codec selection for that endpoint.

The precedence order is:

1. Explicit route codec or `studio()` call.
2. Published application configuration.
3. Package defaults.

Changing `studio_query_key` is package-wide; this change does not introduce a route-level query-key override.

## Validation and errors

The configured codec must be a resolvable class implementing `SkirCodec`. An invalid configured value produces a descriptive package-local `SkirServerException` when Laravel resolves the default codec. Existing codec-specific failures, including a missing optional CBOR dependency, retain their existing exception behavior.

Studio remains disabled by default. Documentation will state that applications enabling Studio should protect the endpoint using their normal Laravel route middleware and authorization strategy. Changing the query key is not an access-control mechanism.

## Documentation

The README remains a concise quick start. Its installation section will add the config publishing command:

```bash
php artisan vendor:publish --tag=skir-server-config
```

It will include a compact configuration example and summarize the route-level precedence rules.

Detailed behavior remains in the existing focused guides:

- `docs/routing.md` documents the global Studio default, configurable query key, per-route enable/disable behavior, and production access warning.
- `docs/codecs.md` documents the class-based default codec, built-in codec classes, container resolution, and per-route override.

## Testing

Feature and unit coverage will prove:

- Package defaults are merged when the config has not been published.
- The config file is publishable under the `skir-server-config` tag.
- Studio remains disabled by default.
- `studio_enabled = true` enables Studio on routes without `->studio()`.
- `->studio(false)` overrides the enabled package default.
- A custom `studio_query_key` opens Studio and the old key no longer does.
- The configured codec class becomes the default for application-wide and isolated route servers.
- An explicit route codec overrides the configured codec.
- Invalid codec configuration produces a package-local exception.
- Existing route, Studio, validation, and codec tests continue to pass.

## Non-goals

This change does not add global URI, prefix, domain, middleware, provider, or route-name settings. Laravel's route declarations already express those concerns directly. It also does not add an `allow_get_requests` option because GET currently supports both RPC query requests and Studio; changing that coupling requires a separate behavioral design.
