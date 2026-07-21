# Studio, Codecs, and Testing

## Studio exposure

Studio is disabled by default. Enable it per route with `studio()` or override a global default with
`studio(false)`. Package configuration uses `studio_enabled` and `studio_query_key`; the query key
only selects the Studio response and is never access control.

```php
Route::skirRpc('/api/skir', [
    Skir::controller(AccountController::class),
])->studio()->middleware('auth:sanctum');
```

Method middleware does not protect Studio. Put authentication and authorization on the outer route
when Studio or every RPC call must share that boundary. Test the Studio GET independently from a
method invocation.

## Codecs

The configured `codec` must implement `SkirCodec`. Laravel resolves the class through the container;
an explicit third argument to `Route::skirRpc()` overrides the package default for that route.

Built-in implementations are `DenseJsonCodec`, `StandardJsonCodec`, `Base64DenseJsonCodec`, and
`CborCodec`. The matching factories are available through `SkirCodecs`. Clients and servers must use
the same codec.

CBOR uses `application/cbor` and requires `spomky-labs/cbor-php`. It is binary serialization, not
privacy or encryption. Selecting it without the optional dependency fails while the route is built.

## Required behavior coverage

Use PHPUnit feature tests at the Laravel boundary and never call a real external service. Cover the
paths applicable to the change:

- valid decoded request, exact encoded response, and persisted/application side effects;
- validation HTTP 422 with `skir_validation_failed` and field details;
- authorization HTTP 403 with `skir_authorization_failed`;
- unauthenticated and unauthorized method calls;
- protected and intentionally public Studio behavior;
- malformed JSON, malformed CBOR, missing method, unknown method, and invalid payload envelopes;
- duplicate method registration from controllers, providers, or manual handlers;
- configured and route-specific codec success plus invalid codec configuration;
- controller middleware ordering, short-circuit responses, and the terminable middleware rejection;
- regressions for explicit `null`, boundary request shapes, and any corrected failure contract.

Assert status, Skir error code/details, response content type/body, authorization boundary, and side
effects. For Studio, assert both rendered procedure metadata and protection on the outer route. For
codec changes, use genuine encoded request/response bodies rather than treating JSON and CBOR as
interchangeable.

When a test fails, first identify whether the mismatch belongs to route protection, method
registration, Laravel resolution, payload decoding, procedure execution, or response encoding. Fix
the owning boundary instead of adding a second dispatch path.
