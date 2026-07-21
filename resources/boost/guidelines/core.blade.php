## php-skir/server

`php-skir/server` exposes SkirRPC procedures through Laravel routing and dispatch. Treat the Skir
schema, generated manifest, and every `/skirout` directory as generator-owned; do not edit files
inside those boundaries. Scaffolded controllers and Form Requests are application-owned.

Prefer Laravel-native controllers, middleware, authorization, Form Requests, and dependency
injection. Before exposing a scaffolded procedure, implement its placeholder and review both
`authorize()` and `rules()`; scaffolded Form Requests deny access and validate nothing by default.

Method middleware does not protect Studio. Put middleware on the outer Skir route when Studio or the
complete endpoint must be private. Keep the server codec aligned with clients, and treat CBOR as
serialization rather than security.

Use the `skir-server-development` skill for procedure routing, scaffolding, Studio, codec, or testing
work.
