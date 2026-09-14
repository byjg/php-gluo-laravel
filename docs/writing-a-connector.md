---
sidebar_position: 5
---

# Writing a connector

A connector is the glue between one ByJG component and Laravel. Each is independent: it declares
the component it needs, and the umbrella provider only registers it when that component is
installed and the configuration has not switched it off.

This is what keeps the package pluggable — `byjg/gluo-laravel` requires no ByJG component, and
adding a new connector never forces anything on the projects that do not use it.

## The contract

Extend `ByJG\Gluo\Laravel\Connector\Connector`, which is a Laravel `ServiceProvider` with three
extra pieces of metadata:

```php
namespace ByJG\Gluo\Laravel\Cache;

use ByJG\CacheEngine\Psr16\BaseCacheEngine;
use ByJG\Gluo\Laravel\Connector\Connector;
use Override;

class CacheConnector extends Connector
{
    #[Override]
    public static function name(): string
    {
        return 'cache';                       // key under `gluo.connectors` and config section
    }

    #[Override]
    public static function requires(): string
    {
        return BaseCacheEngine::class;        // a class that ships with the component
    }

    #[Override]
    public static function package(): string
    {
        return 'byjg/cache-engine';           // named in the error when the component is missing
    }

    #[Override]
    public function register(): void
    {
        $this->app->singleton(BaseCacheEngine::class, /* ... */);
    }

    public function boot(): void
    {
        // aliases, middleware, commands…

        if ($this->app->runningInConsole()) {
            $this->publishes($this->publishables(), static::publishTag());
        }
    }

    /**
     * Starter files this connector contributes, as source => target.
     *
     * @return array<string, string>
     */
    #[Override]
    public function publishables(): array
    {
        return [
            dirname(__DIR__, 2) . '/stubs/CacheExample.php.stub' => base_path('tests/Feature/CacheExample.php'),
        ];
    }

    /**
     * Printed after `gluo:install` finishes.
     *
     * @return string[]
     */
    #[Override]
    public function postInstallNotes(): array
    {
        return ['Point `gluo.cache.driver` at your cache backend.'];
    }
}
```

`publishables()` and `postInstallNotes()` are the only wiring needed for installation.
`gluo:install` loops over the active connectors and reads both — it knows nothing about any
particular connector, so adding one never means editing the command.

The publish tag defaults to `gluo-<name>`, giving each connector its own tag that cannot collide
with another's:

```bash
php artisan vendor:publish --tag=gluo-cache
php artisan gluo:install --connector=cache
```

`requires()` is checked with `class_exists()`/`interface_exists()`, so referencing the class in a
`use` statement is safe even when the component is absent — no autoload is triggered by `::class`.

## Registering it

Add the class to the list in `GluoServiceProvider`:

```php
protected array $connectors = [
    ApiToolsConnector::class,
    CacheConnector::class,
];
```

Then add its default to `config/gluo.php`, alongside the section it reads:

```php
'connectors' => [
    'openapi' => env('GLUO_CONNECTOR_OPENAPI', 'auto'),
    'cache'   => env('GLUO_CONNECTOR_CACHE', 'auto'),
],

'cache' => [
    // …
],
```

`'auto'` is the right default: dormant when the component is absent, active when it is present.

## Activation rules

| `gluo.connectors.<name>` | Component installed | Result                                    |
|--------------------------|---------------------|-------------------------------------------|
| `'auto'` (default)       | yes                 | registered                                |
| `'auto'`                 | no                  | skipped silently                          |
| `true`                   | yes                 | registered                                |
| `true`                   | no                  | `ConnectorNotAvailableException` at boot   |
| `false`                  | either              | skipped                                   |

The active list is resolved on demand from the container, so it always reflects the configuration
in force:

```php
$active = app(\ByJG\Gluo\Laravel\GluoServiceProvider::ACTIVE_CONNECTORS);
```

`gluo:install` uses exactly that to decide whose starter files to publish — a connector that is
not active contributes nothing.

## Conventions

- **One directory per connector**, holding everything it owns: the connector class, middleware,
  test helpers, commands. Deleting the directory and its line in `$connectors` should remove the
  feature completely.
- **Add the component to `suggest`**, never to `require`, and to `require-dev` so its tests can
  run in CI.
- **Namespace after the component**, not after the package: `ByJG\Gluo\Laravel\ApiTools` for
  `byjg/swagger-test`, whose classes live under `ByJG\ApiTools`.
- **Declare starter files in `publishables()`**, never by editing `InstallCommand`. The command
  discovers them, and `publishTag()` gives the connector its own `gluo-<name>` tag for free.
- **Test with `orchestra/testbench`** against a real booted application, including the case where
  the component is missing — `tests/MissingComponentConnector.php` exists for that.
