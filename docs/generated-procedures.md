# Generated procedures

The server package consumes method descriptors and PHP types emitted from a Skir schema. Three generators are supported:

- [`skir-simple-data-objects-generator`](https://github.com/php-skir/skir-simple-data-objects-generator) generates immutable `std-out/simple-data-objects` DTOs and applies their generated validation during request hydration.
- [`skir-laravel-data-generator`](https://github.com/php-skir/skir-laravel-data-generator) generates Spatie Laravel Data objects and applies Laravel Data validation during request hydration.
- [`skir-php-generator`](https://github.com/php-skir/skir-php-generator) generates framework-independent, readonly PHP data objects.

All three generators emit the method descriptors, method references, and provider contracts used by `php-skir/server`.

## Install a generator

For Simple Data Objects:

```bash
composer require php-skir/server std-out/simple-data-objects
npm install --save-dev skir skir-simple-data-objects-generator
```

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

For Simple Data Objects:

```yaml
# skir.yml
generators:
  - mod: skir-simple-data-objects-generator
    outDir: app/Skir/skirout
    config:
      namespace: Skir
```

For Laravel Data objects:

```yaml
# skir.yml
generators:
  - mod: skir-laravel-data-generator
    outDir: app/Skir/skirout
    config:
      namespace: Skir
```

For standard PHP objects:

```yaml
# skir.yml
generators:
  - mod: skir-php-generator
    outDir: app/Skir/skirout
    config:
      namespace: Skir
```

The root namespace defaults to `Skir`, so the `config` block may be omitted when that default is suitable.

Skir requires every configured `outDir` to end in `/skirout`. The suffix marks a generated ownership boundary: Skir may replace or delete any file inside that directory during generation. Keep handwritten controllers, adapters, and procedure implementations outside it.

Source directories below `skir-src` become PHP subnamespaces and output directories. The `.skir` filename does not add a namespace segment:

```text
skir-src/admin/users.skir -> app/Skir/skirout/Admin/GetUserRequestData.php
                          -> Skir\Admin\GetUserRequestData
```

Simple Data Objects and Laravel Data use the `Data` suffix shown above. The standard PHP generator emits `GetUserRequest` for the same schema.

## Request hydration and validation

Controller methods can receive the generated DTO directly or receive a Laravel Form Request. The server selects the generated factory in this order:

1. `makeFromSkirPayload()` for Simple Data Objects and Laravel Data;
2. `fromArray()` for standard PHP objects;
3. `fromSkirValue()` for another compatible application type.

Simple Data Objects and Laravel Data therefore run their generated validation when a decoded object payload is injected directly. Standard PHP objects are hydrated through `fromArray()` and do not add semantic Laravel validation. Use a Form Request when a standard PHP payload needs application validation.

For typed validation, extend `SkirFormRequest` and identify the generated request class:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Skir\Account;

use Illuminate\Contracts\Validation\ValidationRule;
use Skir\Account\UpdateMeRequestData;
use Skir\Server\Http\Requests\SkirFormRequest;

/** @extends SkirFormRequest<UpdateMeRequestData> */
final class UpdateMeFormRequest extends SkirFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'name' => ['required', 'string', 'max:100'],
        ];
    }

    /** @return class-string<UpdateMeRequestData> */
    protected function skirClass(): string
    {
        return UpdateMeRequestData::class;
    }
}
```

Laravel completes input preparation, authorization, and validation before the controller runs. Calling `$request->skir()` then hydrates the complete prepared `$request->all()` bag—not only `$request->validated()`—through the appropriate factory: `makeFromSkirPayload()` for Simple Data Objects and Laravel Data, or `fromArray()` for standard PHP. This retains decoded schema fields when semantic Form Request rules intentionally cover only a subset. Ordinary Laravel Form Requests are also supported when typed DTO access is unnecessary.

See [Scaffolding](scaffolding.md) to generate this class and [Routing](routing.md#form-requests-and-decoded-input) for the request lifecycle.

## Generated server files

For a module containing methods, the generator emits procedure-related files such as:

```text
app/Skir/skirout/Admin/SkirMethods.php
app/Skir/skirout/Admin/AdminSkirMethod.php
app/Skir/skirout/Admin/AbstractSkirProcedures.php
app/Skir/skirout/Admin/SkirProcedures.php
app/Skir/skirout/Admin/SkirProcedureProvider.php
```

- `SkirMethods` exposes the runtime `MethodDescriptor` for each schema method.
- `AdminSkirMethod` is the module-scoped enum used by controller attributes and invokable route definitions.
- `AbstractSkirProcedures` is a generated base class that can be extended to implement and register all procedures in the module.
- `SkirProcedures` is an implementation contract for applications that prefer composition over inheritance.
- `SkirProcedureProvider` registers a `SkirProcedures` implementation with an endpoint.

The generators also emit the request and response types referenced by these contracts. Do not edit any generated file; change the schema or generator configuration and regenerate instead.

## Configure Composer automatically

Run Skir before configuring Composer because the configurator verifies that the output directory exists.

With the Simple Data Objects generator:

```bash
npx skir gen
npx skir-simple-data-objects-generator configure-composer
composer dump-autoload
```

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
      "Skir\\": "app/Skir/skirout/"
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

Replace the configurator command with `skir-simple-data-objects-generator configure-composer` or `skir-php-generator configure-composer` for the selected generator.

## Manual Composer mapping

If Composer configuration is managed manually, map the root namespace to the same generator-owned directory:

```json
{
  "autoload": {
    "psr-4": {
      "Skir\\": "app/Skir/skirout/"
    }
  }
}
```

Then refresh the autoloader:

```bash
composer dump-autoload
```
