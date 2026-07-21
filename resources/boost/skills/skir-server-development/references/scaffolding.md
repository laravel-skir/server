# Scaffolding

## Regenerate before scaffolding

Run the configured Skir generator after schema or generator configuration changes. `skir:make
--generate` runs the configured `generator_command`, reloads every manifest, and stops before writing
controllers or requests when generation fails.

The published command signatures are:

```text
skir:make --generate --all --module=* --method=* --style= --controller= --with-requests --without-requests
skir:make-request {method?} {--name=}
```

Use only compatible options:

- `--all` selects every method and cannot be combined with `--module` or `--method`.
- Repeat `--module=<module>` or `--method=<module.method>` to select specific definitions.
- `--style=module` creates one controller per module.
- `--style=invokable` creates one controller per method.
- `--style=single` writes to one controller; `--controller='App\Skir\AccountController'` selects the
  class and implies `single` when no style is given.
- `--with-requests` creates Form Requests for object payloads; `--without-requests` injects generated
  DTOs directly. They are mutually exclusive.
- Non-interactive `skir:make` calls must pass `--all`, `--module`, or `--method`.

Examples:

```bash
php artisan skir:make --generate --module=Account --style=module --with-requests
php artisan skir:make --method=Account.UpdateMe --style=invokable
php artisan skir:make-request Account.UpdateMe --name=UpdateAccountRequest
```

`skir:make-request` requires an exact, case-sensitive manifest method ID and refuses to replace an
existing request. `--name` changes only the generated request class name.

## Review application-owned output

Generated manifests and PHP under `/skirout` may be replaced; do not edit them. Scaffolded controllers
and Form Requests are application-owned and preserved on later scaffolding runs. The command adds
missing compatible controller methods without replacing existing method bodies and leaves existing
request files unchanged.

Before registration:

1. Replace every controller placeholder with real application behavior.
2. Review generated Form Request `authorize()` and `rules()`; defaults deny access and validate
   nothing.
3. Add the printed `Skir::controller()` or `Skir::method()` registration using the generated enum.
4. Apply method or outer-route middleware according to the endpoint and Studio boundary.
5. Run focused feature tests before broader package/application tests.

If scaffolding cannot find a definition, inspect configured manifest paths and exact method/module
case before rerunning generation. Do not work around a stale manifest by changing generated output.
