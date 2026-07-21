# Procedures and Routing

## Ownership and registration

The Skir schema defines method identity and generated types. Generated method enums and descriptors,
not PHP method names, are authoritative. Every `/skirout` directory and
`skir-server-manifest.json` are generator-owned. Keep controllers, actions, policies, and Form
Requests in application-owned namespaces outside those paths.

Attributed controllers are the primary Laravel workflow:

```php
final class AccountController
{
    #[SkirMethod(AccountSkirMethod::GetMe)]
    #[Middleware('auth:sanctum')]
    #[Authorize('view-account')]
    public function getMe(GetMeFormRequest $request, SkirContext $context): UserData
    {
        return $this->accounts->getMe($request->skir(), $context->request->user());
    }
}
```

Register grouped or invokable controllers with generated identities:

```php
Route::skirRpc('/api/skir', [
    Skir::controller(AccountController::class),
    Skir::method(AccountSkirMethod::UpdateMe, UpdateMeController::class),
]);
```

Laravel's controller dispatcher resolves route parameters, contextual attributes, generated DTOs,
Form Requests, `SkirContext`, and container dependencies. `RequestContext` remains a compatibility
type; new code should use `SkirContext`.

## Middleware and authorization boundaries

Controller-backed procedures support Laravel `#[Middleware(...)]`, `#[Authorize(...)]`, the
`HasMiddleware` interface, and base controller middleware. These apply after a method is selected.
Method middleware does not protect Studio because Studio renders before method selection.

Use middleware on the outer route when it must protect Studio, every procedure, or non-controller
providers. On a shared endpoint, outer middleware also changes every sibling procedure. Decide the
endpoint boundary explicitly rather than accidentally making public methods private.

Terminable controller middleware is unsupported because Skir methods do not run the HTTP kernel's
termination phase. Use non-terminable middleware or move the required work to an appropriate Laravel
boundary.

## Form Requests and errors

Both ordinary Form Requests and `SkirFormRequest` use Laravel input preparation, `authorize()`,
`rules()`, and after-validation hooks. A generated `SkirFormRequest` starts with `authorize()` false
and empty `rules()`; review both before registration. Call `$request->skir()` for its typed payload.

- Validation failure: HTTP 422, `skir_validation_failed`, field details under `error.details`.
- Form Request authorization failure: HTTP 403, `skir_authorization_failed`.
- Controller business exceptions: Laravel's normal exception handling.

Register every generated method identity only once per endpoint. Duplicate controller/provider/manual
registration is a configuration error; remove the duplicate source rather than changing generated
method numbers.
