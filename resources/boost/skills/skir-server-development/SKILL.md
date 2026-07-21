---
name: skir-server-development
description: Use when adding, routing, securing, scaffolding, debugging, or testing SkirRPC procedures in Laravel applications using php-skir/server.
---

# Skir Server Development

Keep generated contracts authoritative and implement procedures through Laravel-native application
boundaries.

## Route the task

- Procedure registration, controllers, middleware, authorization, Form Requests, or dispatch: read
  [procedures and routing](references/procedures-and-routing.md).
- Manifest-driven controller or Form Request generation: read
  [scaffolding](references/scaffolding.md).
- Choosing or implementing generated standard PHP objects, Spatie Laravel Data objects, or Simple
  Data Objects—including input hydration and validation: read
  [structures and validation](references/structures-and-validation.md).
- Studio exposure, codecs, CBOR, failures, or test coverage: read
  [Studio, codecs, and testing](references/studio-codecs-and-testing.md).
- Tasks crossing these areas: read every relevant reference before changing code.

## Workflow

1. Inspect the installed package version, `skir.yml`, current manifest, endpoint routes, and generated
   method enum. Never invent method IDs, command options, or generated types.
2. Change schema or generator configuration first, regenerate, then scaffold application-owned code
   when useful. Do not edit anything in `/skirout` or the generated server manifest.
3. Implement explicit validation and authorization before registration. Decide separately whether
   method middleware or outer-route protection owns the boundary.
4. Test success plus applicable validation, authorization, malformed input, duplicate registration,
   Studio, middleware, and codec failures.
