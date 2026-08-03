---
sidebar_position: 1
---

# Installation and configuration

## Install

```bash
composer require byjg/gluo-laravel
```

That alone brings in no ByJG component — only `illuminate/*`. Add the components whose
connectors you want:

```bash
composer require byjg/swagger-test     # enables the `openapi` connector
```

The service provider is discovered automatically; nothing needs to be added to
`bootstrap/providers.php`.

## Publish the recipe

```bash
php artisan gluo:install
```

This writes:

| File                                | Contents                                              |
|-------------------------------------|-------------------------------------------------------|
| `config/gluo.php`                   | Connector registry and per-connector settings         |
| `tests/Feature/ApiContractTest.php` | A working contract test to start from (`openapi` connector only) |

Existing files are never overwritten unless you pass `--force`.

**Only the connectors that are actually active contribute files.** A connector whose component is
not installed, or that is switched off in the configuration, publishes nothing — so the command
stays useful as more connectors are added.

To install a single connector, name it:

```bash
php artisan gluo:install --connector=openapi
```

The flag is repeatable (`--connector=openapi --connector=cache`). Naming a connector that is not
active reports it rather than failing silently.

If you prefer the standard Laravel route, the same files are available as publish tags. The
configuration has its own tag; each connector publishes under `gluo-<name>`:

```bash
php artisan vendor:publish --tag=gluo-config     # config/gluo.php
php artisan vendor:publish --tag=gluo-openapi    # the openapi connector's starter files
```

## Enabling and disabling connectors

`config/gluo.php` holds a registry with one entry per connector:

```php
'connectors' => [
    'openapi' => env('GLUO_CONNECTOR_OPENAPI', 'auto'),
],
```

| Value    | Behaviour                                                                      |
|----------|--------------------------------------------------------------------------------|
| `'auto'` | Enable when the component is installed, stay dormant otherwise. **Default.**    |
| `true`   | Require it. If the component is missing the application fails at boot, telling you which package to install. |
| `false`  | Never enable it, even when the component is installed.                          |

Use `true` in a project that genuinely depends on the connector — a silent no-op is far harder
to diagnose than a clear message at boot.

## The `openapi` connector

```php
'openapi' => [
    'spec' => env('GLUO_OPENAPI_SPEC', base_path('openapi.json')),
    'allow_null_values' => (bool)env('GLUO_OPENAPI_ALLOW_NULL_VALUES', false),

    'validation' => [
        'request'  => (bool)env('GLUO_OPENAPI_VALIDATE_REQUEST', true),
        'response' => (bool)env('GLUO_OPENAPI_VALIDATE_RESPONSE', false),
        'strict_paths' => (bool)env('GLUO_OPENAPI_STRICT_PATHS', false),
        'error_status' => (int)env('GLUO_OPENAPI_ERROR_STATUS', 400),
        'response_error_status' => (int)env('GLUO_OPENAPI_RESPONSE_ERROR_STATUS', 500),
    ],
],
```

### `spec`

Absolute path to the OpenAPI/Swagger specification, **in JSON** (YAML is not supported by
`byjg/swagger-test`). Swagger 2.0, OpenAPI 3.0.x and OpenAPI 3.1.x all work.

The file is parsed once and shared through the container as `ByJG\ApiTools\Base\Schema`, so
the middleware and the whole test suite read the same instance:

```php
$schema = app(\ByJG\ApiTools\Base\Schema::class);
```

If the path does not exist, resolving it throws `SpecificationNotFoundException` with a message
naming the setting and the environment variable to fix.

### `allow_null_values`

When `true`, properties declared non-nullable may still hold `null`. Keep it `false` to enforce
the contract strictly.

The `validation.*` keys are described in [Runtime validation](runtime-validation.md).

## Generating the specification

This package consumes a specification; it does not produce one. To generate `openapi.json` from
PHP attributes, use [zircote/swagger-php](https://github.com/zircote/swagger-php):

```bash
composer require --dev zircote/swagger-php
vendor/bin/openapi app/Http --output openapi.json --format json
```
