# Generated procedures

The server package consumes method descriptors and PHP types emitted from a Skir schema. Two generators are supported:

- [`skir-laravel-data-generator`](https://github.com/php-skir/skir-laravel-data-generator) generates Spatie Laravel Data objects and applies Laravel Data validation during request hydration.
- [`skir-php-generator`](https://github.com/php-skir/skir-php-generator) generates framework-independent, readonly PHP data objects.

Both generators emit the method descriptors, method references, and provider contracts used by `php-skir/server`.

## Install a generator

For Laravel Data objects:

```bash
composer require php-skir/server spatie/laravel-data
npm install --save-dev skir skir-laravel-data-generator
```

For standard PHP objects:

```bash
composer require php-skir/server
npm install --save-dev skir skir-php-generator
```

## Configure a generator

For Laravel Data objects:

```yaml
# skir.yml
generators:
  - mod: skir-laravel-data-generator
    outDir: skir/skirout
    config:
      namespace: Skir
```

For standard PHP objects:

```yaml
# skir.yml
generators:
  - mod: skir-php-generator
    outDir: skir/skirout
    config:
      namespace: Skir
```

The root namespace defaults to `Skir`, so the `config` block may be omitted when that default is suitable.

Skir requires every configured `outDir` to end in `/skirout`. The suffix marks a generated ownership boundary: Skir may replace or delete any file inside that directory during generation. Keep handwritten controllers, adapters, and procedure implementations outside it.

Source directories below `skir-src` become PHP subnamespaces and output directories. The `.skir` filename does not add a namespace segment:

```text
skir-src/admin/users.skir -> skir/skirout/Admin/GetUserRequestData.php
                          -> Skir\Admin\GetUserRequestData
```

The standard PHP generator emits `GetUserRequest` rather than `GetUserRequestData` for the same schema.

## Generated server files

For a module containing methods, the generator emits procedure-related files such as:

```text
skir/skirout/Admin/SkirMethods.php
skir/skirout/Admin/AdminSkirMethod.php
skir/skirout/Admin/AbstractSkirProcedures.php
skir/skirout/Admin/SkirProcedures.php
skir/skirout/Admin/SkirProcedureProvider.php
```

- `SkirMethods` exposes the runtime `MethodDescriptor` for each schema method.
- `AdminSkirMethod` is the module-scoped enum used by controller attributes and invokable route definitions.
- `AbstractSkirProcedures` is a generated base class that can be extended to implement and register all procedures in the module.
- `SkirProcedures` is an implementation contract for applications that prefer composition over inheritance.
- `SkirProcedureProvider` registers a `SkirProcedures` implementation with an endpoint.

The generators also emit the request and response types referenced by these contracts. Do not edit any generated file; change the schema or generator configuration and regenerate instead.

## Configure Composer automatically

Run Skir before configuring Composer because the configurator verifies that the output directory exists.

With the Laravel Data generator:

```bash
npx skir gen
npx skir-laravel-data-generator configure-composer
composer dump-autoload
```

With the standard PHP generator:

```bash
npx skir gen
npx skir-php-generator configure-composer
composer dump-autoload
```

The generator's `configure-composer` command reads `skir.yml` and `composer.json`, then adds or verifies the PSR-4 mapping for its namespace and output directory. With the configuration above, the resulting mapping is:

```json
{
  "autoload": {
    "psr-4": {
      "Skir\\": "skir/skirout/"
    }
  }
}
```

The command only adjusts `composer.json`; it deliberately does not execute Composer. Run `composer dump-autoload` afterward because the Node and PHP commands may execute in different containers or runtimes.

An existing equivalent mapping is left unchanged. A conflicting mapping fails without being overwritten.

### Package script

Generation and Composer configuration can be combined in `package.json`:

```json
{
  "scripts": {
    "skir:generate": "skir gen && skir-laravel-data-generator configure-composer"
  }
}
```

Then run:

```bash
npm run skir:generate
composer dump-autoload
```

Replace the configurator command with `skir-php-generator configure-composer` when using the standard PHP generator.

## Manual Composer mapping

If Composer configuration is managed manually, map the root namespace to the same generator-owned directory:

```json
{
  "autoload": {
    "psr-4": {
      "Skir\\": "skir/skirout/"
    }
  }
}
```

Then refresh the autoloader:

```bash
composer dump-autoload
```

## Optional Artisan wrapper

The [`php-skir/client`](https://github.com/php-skir/client) package provides an optional Artisan wrapper for running the configured Skir generators:

```bash
php artisan skir:generate-client
```

The client package is not required to host a SkirRPC server. It will usually be installed in a separate application that consumes the server, so server projects can use `npx skir gen` directly.
