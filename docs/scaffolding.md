# Scaffolding Laravel controllers

The server package can scaffold Laravel controllers and Form Requests from the server manifest emitted by each PHP generator. Generated contracts and manifests remain generator-owned; the scaffolder writes normal, application-owned PHP under `App`.

## Configure manifests and destinations

Publish the package configuration when the defaults need to change:

```bash
php artisan vendor:publish --tag=skir-server-config
```

The scaffolding settings are:

```php
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
```

Each configured generator writes `skir-server-manifest.json` at the root of its `outDir`. Keep `manifests` aligned with those output directories when using a different location or multiple generators.

The manifest and generated PHP inside `skirout` are generated-owned. Skir may replace or delete them during generation. Controllers and Form Requests created under the configured application namespaces are application-owned: edit and maintain them like other Laravel code. Rerunning the scaffolder adds missing attributed methods to a compatible controller without replacing existing method bodies, and it leaves existing Form Request files unchanged.

## Generate before scaffolding

Run the generator after every schema or generator-configuration change, then scaffold from the fresh manifest:

```bash
npx skir gen
```

Then use one of the scaffolding commands below. Alternatively, pass `--generate`: the command runs the exact argument list in `generator_command`, reloads every manifest, and only then resolves the requested modules and methods. If generation fails, no controller or Form Request files are scaffolded.

## Commands

Run the interactive selector:

```bash
php artisan skir:make
```

Scaffold every method using configured defaults:

```bash
php artisan skir:make --all
```

Scaffold one module into a module controller and include Form Requests for object payloads:

```bash
php artisan skir:make --module=Account --style=module --with-requests
```

Scaffold one method as an invokable controller:

```bash
php artisan skir:make --method=Account.UpdateMe --style=invokable
```

Regenerate the contracts and manifest before scaffolding every method:

```bash
php artisan skir:make --generate --all
```

Create only the Form Request for one object-request method:

```bash
php artisan skir:make-request Account.UpdateMe
```

`skir:make-request` accepts `--name=UpdateAccountRequest` to override the generated class name. It refuses to replace an existing file.

Generated Form Requests start with `authorize()` returning `false` and an empty `rules()` array. Review both methods before exposing the procedure.

### Selection and layout options

- `--all` selects every manifest method and cannot be combined with `--module` or `--method`.
- `--module=<module>` prefers an exact manifest module name and otherwise accepts one unique case-insensitive match. Ambiguous case-insensitive matches fail and require the exact case-sensitive name. Repeat the option to select several modules.
- `--method=<module.method>` prefers an exact method ID and otherwise accepts one unique case-insensitive match. Ambiguous case-insensitive matches fail and require the exact case-sensitive ID. Repeat the option to select several methods.
- `--style=module` creates one controller per module.
- `--style=invokable` creates one invokable controller per method.
- `--style=single` writes the selection to one controller. Use `--controller='App\Skir\AccountController'` to select its fully qualified class; `--controller` implies the `single` style when no style is given.
- `--with-requests` creates Form Requests for methods whose payload is an object.
- `--without-requests` injects the generated DTO directly instead. These two request options are mutually exclusive.
- `--generate` runs `generator_command` before manifest selection.

Interactive mode prompts for the selection, layout, Form Request preference, and confirmation when their values are not supplied. Non-interactive calls must pass `--all`, `--module`, or `--method`.

The positional method passed to `skir:make-request` must be an exact, case-sensitive manifest method ID; that command does not apply the case-insensitive fallback.

## Register the scaffolded controllers

After writing files, `skir:make` prints the registrations required by the selected layout. A module or single controller uses `Skir::controller()`:

```php
use App\Skir\Account\AccountController;
use Illuminate\Support\Facades\Route;
use Skir\Server\Facades\Skir;

Route::skirRpc('/api/skir', [
    Skir::controller(AccountController::class),
]);
```

An invokable controller maps its generated method enum explicitly:

```php
use App\Skir\Account\UpdateMeController;
use Illuminate\Support\Facades\Route;
use Skir\Account\AccountSkirMethod;
use Skir\Server\Facades\Skir;

Route::skirRpc('/api/skir', [
    Skir::method(AccountSkirMethod::UpdateMe, UpdateMeController::class),
]);
```

See [Routing](routing.md) for controller middleware and authorization, and [Generated procedures](generated-procedures.md) for DTO and Form Request hydration.
