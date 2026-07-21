# Structures and Input Validation

Skir structs become generated PHP request and response types. Select one generator for a generated
ownership boundary and keep its `outDir` ending in `/skirout`. All three generators emit compatible
server descriptors and manifests, but their object and validation contracts differ.

## Choose the representation

| Generator | Generated structure | Incoming struct factory | Validation at direct controller injection |
|---|---|---|---|
| `skir-php-generator` | Framework-independent readonly PHP object; no `Data` suffix | `fromArray()` | Type construction only; no application validation |
| `skir-laravel-data-generator` | `spatie/laravel-data` class with a `Data` suffix | `makeFromSkirPayload()` | Laravel Data inferred rules plus configured overlays |
| `skir-simple-data-objects-generator` | Immutable `std-out/simple-data-objects` class extending `BaseData`, with a `Data` suffix | `makeFromSkirPayload()` | Generated structural rules plus configured overlays, recursively |

Install only the matching PHP library: Laravel Data needs `spatie/laravel-data`; Simple Data Objects
needs `std-out/simple-data-objects`; standard PHP needs neither DTO library.

## Install and configure one generator

Standard PHP:

```bash
composer require php-skir/server
npm install --save-dev skir skir-php-generator
npx skir gen
npx skir-php-generator configure-composer
composer dump-autoload
```

Laravel Data:

```bash
composer require php-skir/server spatie/laravel-data
npm install --save-dev skir skir-laravel-data-generator
npx skir gen
npx skir-laravel-data-generator configure-composer
composer dump-autoload
```

Simple Data Objects:

```bash
composer require php-skir/server std-out/simple-data-objects
npm install --save-dev skir skir-simple-data-objects-generator
npx skir gen
npx skir-simple-data-objects-generator configure-composer
composer dump-autoload
```

For Laravel Data or Simple Data Objects, optional validation overlays use this exact shape (replace
the `mod` value with `skir-simple-data-objects-generator` when selected):

```yaml
generators:
  - mod: skir-laravel-data-generator
    outDir: app/Skir/skirout
    config:
      namespace: Skir
      validation:
        account/profile.skir:
          UpdateProfileRequest:
            email_address:
              - required
              - email:rfc
```

Selectors use the original module path, qualified record name, and Skir field name. Unknown
selectors fail generation. Standard PHP configuration must omit `config.validation` entirely.

The server hydrator prefers `makeFromSkirPayload()`, then `fromArray()`, then `fromSkirValue()`. Do
not add competing factories to generated classes or edit their factory implementation.

Direct DTO hydration and validation never perform authorization. Use Laravel middleware,
`#[Authorize]`, or a Form Request for that separate boundary.

## Standard PHP objects

The standard generator deliberately has no `config.validation` option. `fromArray()` reconstructs
the generated object and nested Skir values, but it does not apply semantic Laravel rules. For
untrusted input, inject a handwritten `SkirFormRequest` and put authorization, normalization, and
rules there; call `$request->skir()` for the typed generated object after Laravel accepts the input.

Choose this representation when generated contracts must remain framework-independent and the
application explicitly owns validation.

## Laravel Data objects

Laravel Data infers structural and nested rules from generated properties. Optional string-rule
overlays belong in the matching generator's `config.validation`, addressed by original Skir module
path, qualified record name, and field name. A configured record receives `#[MergeValidationRules]`
so overlays augment rather than replace inferred rules. Unknown selectors fail generation.

The generated `makeFromSkirPayload()` factory uses `withoutMagicalCreation()` and
`alwaysValidate()`. Use that factory for raw RPC arrays; do not substitute ordinary construction or
another Laravel Data factory as the untrusted transport boundary.

Register custom named rules during Laravel boot before RPC hydration. Keep authorization, database-
aware rules, rule objects, closures, cross-field conditions, uploads, and request-specific
normalization in a handwritten Form Request outside `/skirout`.

Choose Laravel Data when the application already uses its casts, collections, transformations, and
Laravel-integrated validation model.

## Simple Data Objects

Simple Data Objects generates immutable DTOs, mapped Skir field names, and typed direct struct
collections. `makeFromSkirPayload()` validates the raw parent, recursively validates and hydrates
nested structs, then constructs the DTO. Generated structural rules are followed by optional string
rules from `config.validation`, using the same original-Skir selectors as Laravel Data.

`BaseData::from()`, `collection()`, and `lazyCollection()` are trusted hydration paths and do not run
the generated validation contract. Never pass untrusted raw RPC arrays through them. Use
`makeFromSkirPayload()` per raw item, or preferably hydrate the complete generated parent request.
Register custom named rules before hydration. Keep rule objects, closures, authorization, database
checks, normalization, and other request-specific behavior in a handwritten Form Request.

Choose Simple Data Objects when immutable-copy helpers, mapped names, equality/diff APIs, and typed
collections are useful without adopting the full Laravel Data model.

## Form Request boundary

For every representation, `SkirFormRequest` runs Laravel preparation, authorization, rules, and
after-validation hooks before the controller. `$request->skir()` hydrates the complete prepared
`$request->all()` payload through the selected factory, not only `$request->validated()`. Write rules
for the untrusted fields that need application guarantees, and keep schema fields required for DTO
hydration in the prepared input.

Test direct DTO hydration for the chosen generator, invalid nested input, configured custom rules,
Form Request authorization and validation failures, and successful `$request->skir()` hydration.
