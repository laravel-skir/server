# Laravel Controller Dispatch Design

Date: 2026-07-20

## Goal

Make attributed Skir controllers participate in Laravel 13's normal controller lifecycle without turning `php-skir/server` into a parallel controller framework.

The package will continue to own SkirRPC decoding, generated request hydration, `SkirContext` injection, and Skir response encoding. Laravel will own controller middleware discovery, middleware resolution and execution, method dependency injection, contextual parameter attributes, and controller action dispatch.

## Current gap

`ControllerProcedureInvoker` currently resolves the controller through the container and calls its method with `ReflectionMethod::invokeArgs()`. Constructor injection works, but this bypasses Laravel's controller route and dispatcher behavior:

- `#[Middleware]` attributes on controller classes and methods.
- `#[Authorize]`, which is implemented by Laravel as controller middleware.
- Static middleware declared through `HasMiddleware`.
- Middleware registered by controllers extending Laravel's base controller.
- Method dependency injection and contextual parameter attributes.
- The controller's `callAction()` method.
- Bound parameters from the outer Laravel route.

This package must consume those Laravel facilities instead of copying their reflection, filtering, alias resolution, dependency resolution, or action invocation rules.

## Architecture

The package will compose Laravel's existing routing components rather than subclassing `ControllerDispatcher`. Subclassing would couple the package to protected framework internals, while composition keeps the Skir-specific adapter explicit.

The dispatch lifecycle will be:

1. `SkirRpcController` decodes the RPC envelope and selects the registered procedure.
2. A controller-backed procedure exposes the middleware Laravel associates with its controller class and selected method.
3. The package resolves that middleware through Laravel's router and runs it through Laravel's pipeline.
4. The pipeline destination hydrates the decoded Skir request, creates `SkirContext`, and seeds those values plus matching bound route parameters into controller-action metadata.
5. Laravel's `ControllerDispatcher` resolves remaining method dependencies and invokes the action, including `callAction()` when the controller provides it.
6. The package normalizes and encodes the action result into the endpoint's HTTP response.
7. Controller middleware unwinds around the encoded response, allowing middleware to add headers or otherwise inspect the real HTTP response.

Middleware runs before generated DTO hydration. Authentication and authorization can therefore reject a request without first validating or hydrating protected method input.

## Controller action metadata

For every controller-backed Skir procedure, the package will create Laravel route metadata whose action is the actual application controller and method. The metadata is not registered as another public route; it exists only to delegate controller behavior to Laravel.

Laravel's controller middleware discovery will provide:

- Repeatable `#[Middleware]` attributes on classes and methods.
- `only` and `except` filtering based on the selected PHP method name.
- `#[Authorize]` because it extends Laravel's middleware attribute.
- Static `HasMiddleware` declarations.
- Middleware registered through Laravel's base controller.

The router will resolve aliases, middleware groups, and middleware parameters. The package will not parse strings such as `auth:sanctum` itself.

Studio requests are handled before a Skir procedure is selected, so controller middleware will not protect or hide Studio. Applications continue to control Studio through its existing endpoint configuration and route-level middleware. Calls made from Studio to a protected procedure still run that procedure's controller middleware.

## Action parameters

The Skir adapter will build the initial action parameters in reflected method order:

1. A parameter typed as `SkirContext` or the compatibility `RequestContext` receives the current Skir context.
2. A parameter whose name matches a bound parameter on the outer Laravel route receives that bound value, including an implicitly bound model or enum.
3. A generated request object exposing `makeFromSkirPayload()` or `fromArray()` receives the decoded and hydrated method payload.
4. The first remaining built-in parameter receives a scalar or array Skir payload directly.
5. Other parameters remain unresolved so Laravel's `ControllerDispatcher` can inject them from the container or through contextual parameter attributes.

A controller action may have at most one Skir request parameter. Multiple generated request candidates or multiple unbound built-in candidates produce a descriptive package-local exception instead of assigning the payload ambiguously. Generated request hydration remains package-owned because Laravel cannot infer generated Skir DTOs from its route parameters. Existing request hydration precedence remains `makeFromSkirPayload()` before `fromArray()`.

The dispatcher will continue to support methods that accept only `SkirContext`, methods that omit context, and invokable controller registrations.

## Middleware and response behavior

Controller middleware must wrap an encoded Symfony response, not the raw generated response object. This preserves normal behavior for middleware that short-circuits the action, changes response headers, or expects the downstream result to be an HTTP response.

The rules are:

- When the action completes, Skir encodes its declared response type before middleware unwinds.
- When middleware returns a `Response` without calling the action, that response is returned unchanged.
- Authentication, authorization, and other middleware exceptions continue through Laravel's normal exception handler.
- Existing package-local decoding, method lookup, hydration, and codec failures retain their current Skir error responses.
- Manual handlers and generated procedure providers have no controller middleware metadata and preserve their existing behavior.

## Deliberate boundaries

### Form Requests

Laravel cannot resolve an ordinary Form Request correctly from a Skir action because the HTTP request body contains the outer RPC envelope, not the decoded method payload. This change will not claim automatic Form Request support merely because Laravel's method dependency resolver is now used. A Form Request integration must explicitly replace its input with the decoded payload before Laravel validates it.

The Simple Data Objects generator remains the intended validation and hydration path for the Trackr consumer that motivated this change.

### Terminable middleware

Controller middleware selected inside the Skir route does not participate in Laravel HTTP kernel's post-response termination phase. The package will support the middleware `handle()` pipeline but will not invoke `terminate()` early or reproduce the kernel lifecycle internally.

This limitation will be documented. Route-level terminable middleware continues to work normally because it remains part of the outer Laravel route.

### Skir contract ownership

Laravel response preparation, resource controller routing, and model binding from arbitrary RPC payload fields remain outside this feature. Skir's declared response type and codec remain authoritative. URL parameters already bound by the outer route may be injected, but RPC fields are not treated as Laravel route parameters.

## Compatibility

The public `Skir::controller()` and `Skir::method()` APIs will not change. Existing controllers using only generated DTOs and `SkirContext` will keep the same signatures and wire behavior.

The internal registered-procedure contract will gain optional controller middleware metadata. Callables, manual procedures, and generated providers will default to an empty middleware list. Controller-backed procedures will provide middleware and delegate action invocation to Laravel.

This is a runtime feature for Laravel 13 and will use Laravel 13's native controller middleware attribute APIs. It does not introduce a Skir-specific middleware attribute.

## Documentation

The package README will mention Laravel-native controller middleware in the feature list and quick-start controller example. `docs/routing.md` will document:

- Class- and method-level `#[Middleware]` usage.
- `#[Authorize]`, `HasMiddleware`, and middleware aliases.
- Public and protected methods on the same Skir endpoint.
- Studio behavior for protected procedures.
- The Form Request and terminable-middleware boundaries.

## Testing

Focused tests will prove:

- Method-level aliased authentication middleware can protect one Skir method while another method on the same endpoint remains public, without adding Sanctum as a server-package dependency.
- Class-level and method-level middleware are merged in Laravel's order.
- Repeatable attributes and `only` or `except` filters select the expected methods.
- `#[Authorize]` executes through Laravel's authorization middleware.
- `HasMiddleware` and middleware declared through Laravel's base controller execute.
- Constructor injection continues to work.
- Method dependencies and contextual parameter attributes are resolved by Laravel.
- `callAction()` is used when available.
- Bound outer-route parameters are passed to matching action parameters.
- Authentication runs before generated DTO hydration.
- Middleware can add headers to an encoded Skir response.
- Middleware can return a short-circuit response without invoking the controller.
- Direct DTO hydration, context-only actions, invokable controllers, manual handlers, generated providers, codecs, and Studio retain their existing behavior.

The full package test suite, formatter, Composer validation, and whitespace checks must pass before release.

## Release sequence

After implementation is approved and verified:

1. Update the package documentation and version-facing release metadata if required by the existing release workflow.
2. Commit the implementation without mixing unrelated package changes.
3. Push the server package and publish a patch release through its existing GitHub-native release process.
4. Verify the GitHub release and Packagist version.
5. Install that public release into Trackr before implementing the Sanctum and Vue integration.
