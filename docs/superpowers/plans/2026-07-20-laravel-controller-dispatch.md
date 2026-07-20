# Laravel Controller Dispatch Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Integrate the existing Laravel scaffolding branch, expose decoded Skir payloads through Laravel request bags, and dispatch attributed Skir controllers through Laravel 13 middleware, Form Request, dependency-injection, and controller-action facilities.

**Architecture:** Reuse the existing `feature/laravel-scaffolding` worktree as the integration branch and merge current `main` into it. A method-scoped `Illuminate\Http\Request` will preserve transport context while replacing all input bags with the decoded method payload. A thin `SkirControllerDispatcher` extension plus prepared-procedure contract will seed Skir-specific parameters, let Laravel resolve remaining dependencies, and run controller middleware around the encoded response.

**Tech Stack:** PHP 8.4/8.5, Laravel 13 routing and pipeline components, Orchestra Testbench 11, PHPUnit 12, Skir runtime, Simple Data Objects, Spatie Laravel Data compatibility contracts, Docker Compose.

---

## File map

### Existing scaffolding integration

- Modify: `composer.json` — combine current cross-package dependencies with scaffolding runtime dependencies.
- Modify: `config/skir-server.php` — combine codec and Studio defaults with manifest and scaffolding defaults.
- Modify: `src/SkirServerServiceProvider.php` — preserve current codec/provider validation while registering scaffolding and dispatch services.
- Modify: `src/Http/Controllers/SkirRpcController.php` — preserve current transport parsing while moving method execution into the request scope and middleware pipeline.
- Preserve from branch: `src/Commands/**`, `src/Scaffolding/**`, `src/Http/Requests/SkirFormRequest.php`, `src/Hydration/SkirPayloadHydrator.php`, their stubs, and their tests.

### New dispatch boundaries

- Create: `src/Contracts/PreparesProcedure.php` — optional contract for controller-backed handlers that prepare request-specific middleware and invocation.
- Create: `src/PreparedProcedure.php` — immutable request-specific middleware plus invocation closure.
- Create: `src/Http/Requests/SkirMethodRequestScope.php` — clone, sanitize, bind, and restore the decoded method request.
- Create: `src/Routing/PreparedControllerParameters.php` — ordered seeded parameters and nullable Form Request markers.
- Create: `src/Routing/SkirControllerDispatcher.php` — thin Laravel dispatcher extension for seeded parameters and scoped validation errors.
- Modify: `src/RegisteredProcedure.php` — retain preparable handler objects instead of reducing every callable immediately to a closure.
- Modify: `src/Routing/ControllerProcedureInvoker.php` — prepare Laravel route metadata, middleware, controller instance, and seeded Skir parameters.
- Modify: `src/Routing/SkirControllerRouteDefinition.php` — resolve the new controller dispatcher dependencies.
- Modify: `src/Routing/SkirMethodRouteDefinition.php` — do the same for invokable controllers.

### Focused verification

- Create: `tests/Feature/SkirMethodRequestScopeTest.php` — decoded bags, preserved context, and restoration.
- Create: `tests/Feature/LaravelControllerDispatchTest.php` — native middleware, authorization, DI, contextual attributes, `callAction()`, and response behavior.
- Modify: `tests/Feature/SkirFormRequestTest.php` — native ordinary and typed Form Request resolution for all DTO factories.
- Modify: `tests/Feature/NullableControllerRequestTest.php` — nullable authorization and null action semantics through the new dispatcher.
- Modify: `tests/Feature/ControllerProcedureInvokerTest.php` — direct DTO/context regression coverage.
- Modify: `tests/Feature/SkirRpcControllerTest.php` — pipeline short-circuiting and encoded-response middleware.

### Documentation

- Modify: `README.md` — concise feature and quick-start references.
- Create: `docs/scaffolding.md` — move the branch's detailed `skir:make` and `skir:make-request` instructions out of the README.
- Modify: `docs/generated-procedures.md` — three DTO targets and generation/scaffolding sequence.
- Modify: `docs/routing.md` — native Laravel controller attributes, decoded request lifecycle, Studio, and limitations.

---

### Task 1: Integrate the complete scaffolding branch with current main

**Files:**
- Modify: `README.md`
- Modify: `composer.json`
- Modify: `config/skir-server.php`
- Modify: `src/Http/Controllers/SkirRpcController.php`
- Modify: `src/SkirServerServiceProvider.php`
- Preserve: all files added by `feature/laravel-scaffolding`

- [ ] **Step 1: Verify both source branches and the existing worktree are clean**

Run from the Trackr root:

```bash
git -C packages/server status --short --branch
git -C packages/server/.worktrees/laravel-scaffolding status --short --branch
git -C packages/server rev-list --left-right --count main...feature/laravel-scaffolding
```

Expected: both worktrees are clean; the existing worktree is on `feature/laravel-scaffolding`; both sides have commits to integrate.

- [ ] **Step 2: Run both pre-merge baselines**

Run from the Trackr root:

```bash
docker compose run --rm --no-deps app sh -lc 'cd packages/server && vendor/bin/phpunit'
docker compose run --rm --no-deps app sh -lc 'cd packages/server/.worktrees/laravel-scaffolding && vendor/bin/phpunit'
```

Expected: current `main` reports `OK (97 tests, 251 assertions)` and the scaffolding branch reports its complete suite as passing.

- [ ] **Step 3: Merge current main into the feature worktree**

Run from `packages/server/.worktrees/laravel-scaffolding`:

```bash
git merge main
```

Expected conflicts: `README.md`, `config/skir-server.php`, `src/Http/Controllers/SkirRpcController.php`, and `src/SkirServerServiceProvider.php`. Do not abort or discard either history.

- [ ] **Step 4: Resolve the combined package configuration**

Make `config/skir-server.php` contain the current runtime settings and the branch's scaffolding settings:

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

- [ ] **Step 5: Resolve Composer requirements without losing cross-package tests**

The final relevant `composer.json` sections must include the scaffolding runtime dependencies and the existing client development dependency/repository:

```json
{
  "require": {
    "php": "^8.4",
    "laravel/framework": "^13.0",
    "nikic/php-parser": "^5.8",
    "php-skir/runtime": "^0.1@dev",
    "symfony/process": "^7.4|^8.0"
  },
  "require-dev": {
    "laravel/pint": "^1.29",
    "orchestra/testbench": "^11.0",
    "php-skir/client": "^0.1@dev",
    "phpunit/phpunit": "^12.5",
    "spomky-labs/cbor-php": "^3.2"
  },
  "repositories": [
    {
      "type": "path",
      "url": "../runtime",
      "options": { "symlink": false }
    },
    {
      "type": "path",
      "url": "../client",
      "options": { "symlink": false }
    }
  ]
}
```

Keep the existing metadata, autoload, scripts, suggestions, and Composer configuration around these sections unchanged.

- [ ] **Step 6: Resolve the service provider additively**

Preserve the current validated `SkirCodec` binding and route-provider error behavior. Add the scaffolding registrations without replacing them:

```php
$this->app->singleton(ManifestRepository::class);
$this->app->bind(GeneratorRunner::class, SymfonyGeneratorRunner::class);
$this->app->bind(ControllerScaffolding::class, ControllerScaffolder::class);
```

Preserve command registration:

```php
if ($this->app->runningInConsole()) {
    $this->commands([
        MakeSkirCommand::class,
        MakeSkirRequestCommand::class,
    ]);
}
```

Merge the nested `scaffolding` defaults with configured values in `register()` while leaving `codec`, Studio settings, and provider validation intact.

- [ ] **Step 7: Resolve transport controller and README conflicts conservatively**

Keep current `main`'s explicit JSON object validation and current concise README structure. Preserve the scaffolding branch's PHP runtime classes and commands; detailed command documentation will move to `docs/scaffolding.md` in Task 7.

At this checkpoint, `SkirRpcController` should retain its existing direct invocation. The request scope and middleware pipeline are introduced test-first in later tasks.

- [ ] **Step 8: Refresh the feature worktree dependencies**

Run from the Trackr root:

```bash
docker compose run --rm --no-deps app sh -lc 'cd packages/server/.worktrees/laravel-scaffolding && composer update --prefer-stable --prefer-dist --no-interaction --no-progress'
```

Expected: Composer resolves Laravel 13, PHP Parser, Symfony Process, runtime, client, and test dependencies successfully.

- [ ] **Step 9: Verify and finish the merge commit**

Run:

```bash
git -C packages/server/.worktrees/laravel-scaffolding diff --check
git -C packages/server/.worktrees/laravel-scaffolding status --short
docker compose run --rm --no-deps app sh -lc 'cd packages/server/.worktrees/laravel-scaffolding && vendor/bin/phpunit'
```

Expected: no unresolved files, no whitespace errors, and the merged suite passes.

Then run from the feature worktree:

```bash
git add README.md composer.json config/skir-server.php src tests stubs
git commit
```

Use the merge message `Merge current server main into Laravel scaffolding` if Git does not retain the default merge message.

---

### Task 2: Add the method-scoped decoded Laravel request

**Files:**
- Create: `src/Http/Requests/SkirMethodRequestScope.php`
- Create: `tests/Feature/SkirMethodRequestScopeTest.php`

- [ ] **Step 1: Write failing request-scope tests**

Add tests proving that a JSON transport request with outer `method`, `request`, query values, files, headers, route/user/session resolvers, and raw content produces a method request where:

```php
$this->assertSame([
    'email' => 'maxim@example.com',
    'name' => 'Maxim',
], $methodRequest->all());
$this->assertSame([], $methodRequest->query->all());
$this->assertSame([], $methodRequest->files->all());
$this->assertSame('Bearer token', $methodRequest->header('Authorization'));
$this->assertSame($transportContent, $methodRequest->getContent());
$this->assertSame($methodRequest, app('request'));
```

Add a second test whose callback throws `RuntimeException('dispatch failed')` and assert afterward:

```php
$this->assertSame($transportRequest, app('request'));
```

Add scalar and null payload cases and assert that their method request input bags are empty while the callback still receives the original scalar or null separately.

- [ ] **Step 2: Run the focused test and verify failure**

Run from the Trackr root:

```bash
docker compose run --rm --no-deps app sh -lc 'cd packages/server/.worktrees/laravel-scaffolding && vendor/bin/phpunit tests/Feature/SkirMethodRequestScopeTest.php'
```

Expected: FAIL because `SkirMethodRequestScope` does not exist.

- [ ] **Step 3: Implement the request scope**

Create `src/Http/Requests/SkirMethodRequestScope.php`:

```php
<?php

declare(strict_types=1);

namespace Skir\Server\Http\Requests;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\InputBag;

final readonly class SkirMethodRequestScope
{
    public function __construct(private Container $container) {}

    public function run(Request $transportRequest, mixed $payload, Closure $callback): mixed
    {
        $originalRequest = $this->container->make('request');
        $methodRequest = Request::createFrom($transportRequest);

        $methodRequest->query->replace([]);
        $methodRequest->request->replace([]);
        $methodRequest->files->replace([]);
        $methodRequest->setJson(new InputBag);

        if (is_array($payload)) {
            $methodRequest->replace($payload);
        }

        $this->container->instance('request', $methodRequest);

        try {
            return $callback($methodRequest, $payload);
        } finally {
            $this->container->instance('request', $originalRequest);
        }
    }
}
```

Use a PHPDoc closure signature if Pint or static analysis requires it; do not mutate `$transportRequest`.

- [ ] **Step 4: Run tests and format**

Run:

```bash
docker compose run --rm --no-deps app sh -lc 'cd packages/server/.worktrees/laravel-scaffolding && vendor/bin/phpunit tests/Feature/SkirMethodRequestScopeTest.php'
docker compose run --rm --no-deps app sh -lc 'cd packages/server/.worktrees/laravel-scaffolding && vendor/bin/pint --dirty --format agent'
```

Expected: request-scope tests pass and Pint reports no remaining changes after formatting.

- [ ] **Step 5: Commit**

Run from the feature worktree:

```bash
git add src/Http/Requests/SkirMethodRequestScope.php tests/Feature/SkirMethodRequestScopeTest.php
git commit -m "Scope Laravel requests to decoded Skir payloads"
```

---

### Task 3: Introduce prepared procedures and Laravel controller dispatch

**Files:**
- Create: `src/Contracts/PreparesProcedure.php`
- Create: `src/PreparedProcedure.php`
- Create: `src/Routing/PreparedControllerParameters.php`
- Create: `src/Routing/SkirControllerDispatcher.php`
- Modify: `src/RegisteredProcedure.php`
- Modify: `src/Routing/ControllerProcedureInvoker.php`
- Modify: `src/Routing/SkirControllerRouteDefinition.php`
- Modify: `src/Routing/SkirMethodRouteDefinition.php`
- Create: `tests/Feature/LaravelControllerDispatchTest.php`

- [ ] **Step 1: Write failing native-dispatch tests**

Create controller fixtures that prove:

```php
#[Middleware(RecordControllerMiddleware::class)]
final class NativeDispatchController extends Controller
{
    #[SkirMethod(NativeDispatchMethod::Update)]
    #[Middleware(RecordMethodMiddleware::class)]
    public function update(
        NativePayload $request,
        SkirContext $context,
        NativeDependency $dependency,
    ): NativePayload {
        return new NativePayload("{$request->name}:{$dependency->value}");
    }
}
```

Assert class middleware runs before method middleware, the dependency comes from the container, the decoded DTO and context are injected once, and the result is encoded normally. Add a controller extending Laravel's base controller that overrides `callAction()` and assert the override is called.

Add `HasMiddleware` and contextual parameter-attribute fixtures in the same test file. Use Laravel's native `#[Config('app.name')]` or a small test contextual attribute and assert it resolves through the controller dispatcher.

- [ ] **Step 2: Run the focused test and verify failure**

```bash
docker compose run --rm --no-deps app sh -lc 'cd packages/server/.worktrees/laravel-scaffolding && vendor/bin/phpunit tests/Feature/LaravelControllerDispatchTest.php'
```

Expected: FAIL because procedures do not expose controller middleware and method dependencies receive the decoded DTO payload.

- [ ] **Step 3: Add the prepared-procedure contract**

Create the contract:

```php
interface PreparesProcedure
{
    public function prepare(mixed $request, SkirContext $context): PreparedProcedure;
}
```

Create `PreparedProcedure` with a `list<Closure|string>` middleware property, a private invocation closure created with `Closure::fromCallable()`, and an `invoke(): mixed` method.

Modify `RegisteredProcedure` so it retains both:

```php
private Closure $handler;
private ?PreparesProcedure $preparer;
```

Its `prepare()` method delegates to the preparer when available and otherwise returns an empty-middleware `PreparedProcedure` wrapping the existing manual handler. Keep `invoke()` for compatibility tests until every internal caller uses `prepare()`.

- [ ] **Step 4: Add prepared controller parameters**

`PreparedControllerParameters` must hold an ordered list of values plus object IDs that represent nullable Form Requests which must become `null` immediately before action invocation:

```php
final readonly class PreparedControllerParameters
{
    /**
     * @param list<mixed> $values
     * @param list<int> $nullObjectIds
     */
    public function __construct(
        public array $values,
        private array $nullObjectIds = [],
    ) {}

    /** @param list<mixed> $values */
    public function restoreNulls(array $values): array
    {
        return array_map(
            fn (mixed $value): mixed => is_object($value)
                && in_array(spl_object_id($value), $this->nullObjectIds, true)
                    ? null
                    : $value,
            $values,
        );
    }
}
```

- [ ] **Step 5: Extend Laravel's controller dispatcher at the parameter seam**

Create `SkirControllerDispatcher extends Illuminate\Routing\ControllerDispatcher`. Its `dispatch()` must follow Laravel 13's implementation but scope validation and authorization translation to dependency resolution:

```php
public function dispatch(Route $route, $controller, $method): mixed
{
    try {
        $parameters = $this->resolveParameters($route, $controller, $method);
    } catch (ValidationException $exception) {
        throw SkirServerException::validationFailed($exception->errors());
    } catch (AuthorizationException) {
        throw SkirServerException::authorizationFailed();
    }

    $prepared = $route->getAction('skirParameters');

    if ($prepared instanceof PreparedControllerParameters) {
        $parameters = $prepared->restoreNulls($parameters);
    }

    if (method_exists($controller, 'callAction')) {
        return $controller->callAction($method, $parameters);
    }

    return $controller->{$method}(...array_values($parameters));
}
```

Override `resolveParameters()` to pass `PreparedControllerParameters::$values` through inherited `resolveClassMethodDependencies()`. Fall back to `parent::resolveParameters()` only when no Skir prepared parameters exist.

- [ ] **Step 6: Refactor controller invocation into preparation**

Make `ControllerProcedureInvoker` implement `PreparesProcedure`. For each request it must:

1. Clone the outer route and replace its action with `ControllerClass@method`.
2. Resolve one controller instance from that action route.
3. Build reflected parameters in method order:
   - decoded generated DTO through `SkirPayloadHydrator`;
   - `SkirContext` / `RequestContext`;
   - matching bound outer-route parameters;
   - nullable Form Request authorization marker from the existing resolver;
   - leave ordinary Form Requests and services absent for Laravel.
4. Store `PreparedControllerParameters` in the synthetic route action.
5. Resolve `$route->controllerMiddleware()` through `Router::resolveMiddleware()`.
6. Return `PreparedProcedure` whose closure calls `SkirControllerDispatcher::dispatch()` and normalizes the action response.

Do not reflect Laravel's `Middleware` or `Authorize` attributes in Skir code; the synthetic route must delegate discovery to Laravel.

- [ ] **Step 7: Resolve invokers through the container**

Update both route-definition classes to resolve `ControllerProcedureInvoker` with its controller and method arguments through the application container. Register `SkirControllerDispatcher` in `SkirServerServiceProvider` as a transient binding so it receives Laravel's container.

- [ ] **Step 8: Run focused and regression tests**

```bash
docker compose run --rm --no-deps app sh -lc 'cd packages/server/.worktrees/laravel-scaffolding && vendor/bin/phpunit tests/Feature/LaravelControllerDispatchTest.php tests/Feature/ControllerProcedureInvokerTest.php tests/Feature/SkirRouteConfigurationTest.php'
docker compose run --rm --no-deps app sh -lc 'cd packages/server/.worktrees/laravel-scaffolding && vendor/bin/pint --dirty --format agent'
```

Expected: native dispatch and existing controller-registration tests pass.

- [ ] **Step 9: Commit**

```bash
git add src/Contracts/PreparesProcedure.php src/PreparedProcedure.php src/RegisteredProcedure.php src/Routing src/SkirServerServiceProvider.php tests/Feature/LaravelControllerDispatchTest.php tests/Feature/ControllerProcedureInvokerTest.php
git commit -m "Dispatch Skir controllers through Laravel"
```

---

### Task 4: Make Form Requests consume decoded bags for all DTO targets

**Files:**
- Modify: `src/Http/Requests/SkirFormRequest.php`
- Modify: `src/Http/Requests/SkirFormRequestResolver.php`
- Modify: `src/Hydration/SkirPayloadHydrator.php`
- Modify: `tests/Feature/SkirFormRequestTest.php`
- Modify: `tests/Feature/NullableControllerRequestTest.php`

- [ ] **Step 1: Replace manual-resolution assertions with native lifecycle assertions**

Add an ordinary Laravel Form Request fixture and a `SkirFormRequest` fixture. In `prepareForValidation()`, merge a trimmed name and record:

```php
self::$seenInput = $this->all();
self::$seenRoute = $this->route('tenant');
self::$seenUser = $this->user()?->getAuthIdentifier();
self::$seenTrace = $this->header('X-Skir-Trace');
```

Assert the initial input contains only the decoded method object, never `method`, the outer `request` wrapper, or transport query keys. Assert rules, `after()`, authorization, route/user/session/header access, and prepared input all run through Laravel.

- [ ] **Step 2: Add the three DTO hydration cases**

Use lightweight fixtures exposing the real generator contracts:

```php
final readonly class SimpleDataObjectFixture
{
    public static function makeFromSkirPayload(array $payload): self;
}

final readonly class LaravelDataFixture
{
    public static function makeFromSkirPayload(array $payload): self;
}

final readonly class StandardPhpFixture
{
    public static function fromArray(array $payload): self;
}
```

Give each fixture a static counter/factory label and assert `SkirFormRequest::skir()` selects the intended factory once, after Form Request preparation and validation.

- [ ] **Step 3: Run tests and verify the current resolver path fails**

```bash
docker compose run --rm --no-deps app sh -lc 'cd packages/server/.worktrees/laravel-scaffolding && vendor/bin/phpunit tests/Feature/SkirFormRequestTest.php tests/Feature/NullableControllerRequestTest.php'
```

Expected before refactoring: the ordinary Form Request test fails because the current invoker rejects it, and the container-binding assertion fails because the transport request is still bound.

- [ ] **Step 4: Reduce the custom resolver to Skir-only nullable behavior**

For non-null object payloads, let Laravel's `ControllerDispatcher` resolve Form Requests from `app('request')`. Keep `SkirFormRequestResolver` only for the nullable-null compatibility path that must run preparation and authorization before supplying `null` to the action.

Change `authorizeNull()` to return the prepared Form Request object so `PreparedControllerParameters` can use its object ID as a temporary dependency marker. Do not call `rules()` or `validateResolved()` for this null path.

Keep exception translation scoped as follows:

- dependency-resolution `ValidationException` -> `skir_validation_failed`;
- dependency-resolution `AuthorizationException` -> `skir_authorization_failed`;
- the same exception types thrown inside controller business logic -> Laravel's normal exception handler.

- [ ] **Step 5: Verify DTO and nullable behavior**

```bash
docker compose run --rm --no-deps app sh -lc 'cd packages/server/.worktrees/laravel-scaffolding && vendor/bin/phpunit tests/Feature/SkirFormRequestTest.php tests/Feature/NullableControllerRequestTest.php tests/Feature/ControllerProcedureInvokerTest.php'
docker compose run --rm --no-deps app sh -lc 'cd packages/server/.worktrees/laravel-scaffolding && vendor/bin/pint --dirty --format agent'
```

Expected: all focused tests pass, including preservation of null controller arguments and all three generator factories.

- [ ] **Step 6: Commit**

```bash
git add src/Http/Requests src/Hydration tests/Feature/SkirFormRequestTest.php tests/Feature/NullableControllerRequestTest.php tests/Feature/ControllerProcedureInvokerTest.php
git commit -m "Resolve Skir Form Requests from decoded input"
```

---

### Task 5: Run procedure middleware around encoded Skir responses

**Files:**
- Modify: `src/Http/Controllers/SkirRpcController.php`
- Modify: `tests/Feature/LaravelControllerDispatchTest.php`
- Modify: `tests/Feature/SkirRpcControllerTest.php`
- Modify: `tests/Feature/StudioRendererTest.php`

- [ ] **Step 1: Add failing method-level middleware tests**

Add two methods to one controller:

```php
#[SkirMethod(AccountMethod::Login)]
public function login(LoginRequest $request): LoginResponse;

#[SkirMethod(AccountMethod::GetMe)]
#[Middleware('test-auth')]
public function getMe(SkirContext $context): UserResponse;
```

Register `test-auth` as a test middleware alias. Assert Login succeeds anonymously, GetMe short-circuits with HTTP 401, and an authenticated GetMe succeeds.

Add middleware that appends `X-Skir-Middleware: applied` after `$next($request)`. Assert the header is present and the middleware receives a Symfony `Response`, not a DTO.

Add `#[Authorize('view-account')]` with a test gate and assert denial and success. Add a Studio GET assertion proving Studio remains visible while protected procedure calls still require middleware.

- [ ] **Step 2: Run focused tests and verify failure**

```bash
docker compose run --rm --no-deps app sh -lc 'cd packages/server/.worktrees/laravel-scaffolding && vendor/bin/phpunit tests/Feature/LaravelControllerDispatchTest.php tests/Feature/SkirRpcControllerTest.php'
```

Expected: FAIL because `SkirRpcController` still invokes procedures before running their prepared middleware.

- [ ] **Step 3: Move execution into request scope and Laravel Pipeline**

Inject `Container` and `SkirMethodRequestScope` into `SkirRpcController`. After transport decoding, execute:

```php
return $this->requestScope->run(
    $request,
    $decodedRequest,
    function (Request $methodRequest, mixed $decodedRequest) use ($codec, $procedure): Response {
        $context = new RequestContext($methodRequest, $procedure->descriptor);
        $prepared = $procedure->prepare($decodedRequest, $context);

        return (new Pipeline($this->container))
            ->send($methodRequest)
            ->through($prepared->middleware)
            ->then(fn (): Response => $this->encodeResponse(
                $codec,
                $procedure,
                $prepared->invoke(),
            ));
    },
);
```

Extract `encodeResponse()` from the current inline encoding logic. Keep Studio detection and transport decoding outside the method request scope. Leave manual procedures with an empty middleware list.

- [ ] **Step 4: Verify response and Studio behavior**

```bash
docker compose run --rm --no-deps app sh -lc 'cd packages/server/.worktrees/laravel-scaffolding && vendor/bin/phpunit tests/Feature/LaravelControllerDispatchTest.php tests/Feature/SkirRpcControllerTest.php tests/Feature/StudioRendererTest.php tests/Feature/SkirRpcValidationTest.php'
docker compose run --rm --no-deps app sh -lc 'cd packages/server/.worktrees/laravel-scaffolding && vendor/bin/pint --dirty --format agent'
```

Expected: public/protected method tests, response header, authorization, short-circuit response, Studio, and existing validation tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/Http/Controllers/SkirRpcController.php tests/Feature/LaravelControllerDispatchTest.php tests/Feature/SkirRpcControllerTest.php tests/Feature/StudioRendererTest.php
git commit -m "Run Laravel middleware around Skir procedures"
```

---

### Task 6: Close compatibility and edge-case coverage

**Files:**
- Modify: `tests/Feature/LaravelControllerDispatchTest.php`
- Modify: `tests/Feature/SkirFormRequestTest.php`
- Modify: `tests/Feature/NullableControllerRequestTest.php`
- Modify: `tests/Feature/SkirRouteConfigurationTest.php`

- [ ] **Step 1: Add missing controller compatibility cases**

Add focused tests for:

- repeatable `#[Middleware]` attributes;
- class `only` / `except` filters based on the selected PHP method;
- legacy Laravel base-controller middleware;
- `HasMiddleware` static declarations;
- invokable `Skir::method()` controllers;
- bound route model or enum parameters copied from the outer route;
- context-only and no-payload procedures;
- ambiguous multiple direct Skir request parameters producing a package-local exception;
- middleware not running for manual/generated providers;
- container request restoration after middleware, validation, hydration, action, and codec exceptions.

- [ ] **Step 2: Run tests and implement only the missing behavior**

```bash
docker compose run --rm --no-deps app sh -lc 'cd packages/server/.worktrees/laravel-scaffolding && vendor/bin/phpunit tests/Feature/LaravelControllerDispatchTest.php tests/Feature/SkirFormRequestTest.php tests/Feature/NullableControllerRequestTest.php tests/Feature/SkirRouteConfigurationTest.php'
```

Expected: new tests initially fail at the exact unsupported behavior. Limit production changes to `SkirMethodRequestScope`, `PreparedControllerParameters`, `SkirControllerDispatcher`, or `ControllerProcedureInvoker`, according to which assertion failed; do not add another dispatcher or middleware parser.

- [ ] **Step 3: Format and rerun the focused set**

```bash
docker compose run --rm --no-deps app sh -lc 'cd packages/server/.worktrees/laravel-scaffolding && vendor/bin/pint --dirty --format agent'
docker compose run --rm --no-deps app sh -lc 'cd packages/server/.worktrees/laravel-scaffolding && vendor/bin/phpunit tests/Feature/LaravelControllerDispatchTest.php tests/Feature/SkirFormRequestTest.php tests/Feature/NullableControllerRequestTest.php tests/Feature/SkirRouteConfigurationTest.php'
```

Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add src tests/Feature
git commit -m "Cover Laravel controller dispatch edge cases"
```

---

### Task 7: Document scaffolding, DTO targets, middleware, and limitations

**Files:**
- Modify: `README.md`
- Create: `docs/scaffolding.md`
- Modify: `docs/generated-procedures.md`
- Modify: `docs/routing.md`

- [ ] **Step 1: Preserve a concise README**

Add feature bullets for Laravel-native controller middleware and Form Requests. Link to `docs/scaffolding.md`, `docs/generated-procedures.md`, and `docs/routing.md`. Keep detailed command options and dispatch internals out of the README.

- [ ] **Step 2: Move complete scaffolding instructions into a focused guide**

Document these exact public command shapes in `docs/scaffolding.md`:

```bash
php artisan skir:make
php artisan skir:make --all
php artisan skir:make --module=Account --style=module --with-requests
php artisan skir:make --method=Account.UpdateMe --style=invokable
php artisan skir:make --generate --all
php artisan skir:make-request Account.UpdateMe
```

Explain generated-owned manifests, application-owned additive controllers/Form Requests, and the requirement to regenerate before scaffolding when schemas change.

- [ ] **Step 3: Document the three DTO paths**

In `docs/generated-procedures.md`, show that:

- Simple Data Objects and Laravel Data use `makeFromSkirPayload()`;
- standard PHP uses `fromArray()`;
- `SkirFormRequest::skir()` chooses the correct factory after Laravel validation;
- direct DTO injection remains available but standard PHP direct hydration does not add semantic validation.

- [ ] **Step 4: Document native controller behavior**

In `docs/routing.md`, include:

```php
use Illuminate\Routing\Attributes\Controllers\Middleware;

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

Explain decoded input bags, ordinary and typed Form Requests, `#[Authorize]`, `HasMiddleware`, Studio remaining endpoint-visible, and the explicit terminable-controller-middleware limitation.

- [ ] **Step 5: Verify documentation and commit**

Run:

```bash
git -C packages/server/.worktrees/laravel-scaffolding diff --check
rg -n "docs/(scaffolding|generated-procedures|routing)\.md" packages/server/.worktrees/laravel-scaffolding/README.md
```

Expected: no whitespace errors and every linked guide exists.

Commit from the feature worktree:

```bash
git add README.md docs/scaffolding.md docs/generated-procedures.md docs/routing.md
git commit -m "Document Laravel-native Skir controllers"
```

---

### Task 8: Verify, integrate, publish, and confirm the server release

**Files:**
- Verify: all server package files
- Modify: only the dispatch, request-scope, tests, or documentation files already named in Tasks 2-7 when their corresponding verification fails

- [ ] **Step 1: Run package formatting and complete verification**

Run from the Trackr root:

```bash
docker compose run --rm --no-deps app sh -lc 'cd packages/server/.worktrees/laravel-scaffolding && vendor/bin/pint --dirty --format agent'
docker compose run --rm --no-deps app sh -lc 'cd packages/server/.worktrees/laravel-scaffolding && composer validate --strict'
docker compose run --rm --no-deps app sh -lc 'cd packages/server/.worktrees/laravel-scaffolding && vendor/bin/phpunit'
git -C packages/server/.worktrees/laravel-scaffolding diff --check
git -C packages/server/.worktrees/laravel-scaffolding status --short --branch
```

Expected: Composer is valid, the full merged suite passes, diff checks pass, and the feature worktree is clean.

- [ ] **Step 2: Review the complete feature diff**

Run:

```bash
git -C packages/server diff --stat main...feature/laravel-scaffolding
git -C packages/server log --oneline --decorate main..feature/laravel-scaffolding
```

Confirm the diff contains the previously approved scaffolding work, controller dispatch integration, tests, and docs—without unrelated root-project files.

- [ ] **Step 3: Merge the verified feature branch into local main**

Run from `packages/server`:

```bash
git merge --no-ff feature/laravel-scaffolding -m "Merge Laravel-native controller dispatch"
```

Expected: clean merge. Rerun the full Docker PHPUnit command on `packages/server` after the merge.

- [ ] **Step 4: Push main and verify GitHub Actions**

Run:

```bash
git push origin main
gh run list --repo php-skir/server --branch main --limit 5
```

Wait for the new Tests workflow and both PHP 8.4/8.5 jobs to pass. If a workflow fails, inspect it with:

```bash
gh run view --repo php-skir/server --log-failed "$(gh run list --repo php-skir/server --branch main --limit 20 --json databaseId,conclusion --jq 'map(select(.conclusion == "failure"))[0].databaseId')"
```

- [ ] **Step 5: Create and publish the patch release**

Verify that `v0.1.3` does not already exist:

```bash
gh release view v0.1.3 --repo php-skir/server
```

Expected before release: not found. Then run:

```bash
git tag -a v0.1.3 -m "v0.1.3"
git push origin v0.1.3
gh release create v0.1.3 --repo php-skir/server --verify-tag --generate-notes --title v0.1.3
```

- [ ] **Step 6: Verify the public release and registry**

Run:

```bash
gh release view v0.1.3 --repo php-skir/server --json tagName,isDraft,isPrerelease,publishedAt,url
```

Expected: tag `v0.1.3`, not draft, not prerelease.

Run the fresh Packagist consumer check:

```bash
docker compose run --rm --no-deps app sh -lc '
skir_consumer_dir="$(mktemp -d /tmp/php-skir-server-v013.XXXXXX)"
COMPOSER_HOME="${skir_consumer_dir}/composer-home" composer --working-dir="${skir_consumer_dir}" init --no-interaction --name=php-skir/release-check
COMPOSER_HOME="${skir_consumer_dir}/composer-home" composer --working-dir="${skir_consumer_dir}" require php-skir/server:^0.1.3 --no-interaction --no-progress
COMPOSER_HOME="${skir_consumer_dir}/composer-home" composer --working-dir="${skir_consumer_dir}" show php-skir/server
'
```

Expected: `versions : * v0.1.3` and no path repository in the generated consumer `composer.json`. If Packagist has not refreshed yet, wait and rerun the same block rather than changing package metadata.

- [ ] **Step 7: Record the handoff state**

Run:

```bash
git -C packages/server status --short --branch
git -C packages/server describe --tags --exact-match
```

Expected: clean `main`, synchronized with `origin/main`, exactly at `v0.1.3`. The next project phase installs this public release into Trackr and implements Sanctum plus the selected Vue/Shadcn app shell.
