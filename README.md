---
sidebar_key: gluo-laravel
tags: [php, laravel, openapi, testing]
---

# Gluo for Laravel

[![Sponsor](https://img.shields.io/badge/Sponsor-%23ea4aaa?logo=githubsponsors&logoColor=white&labelColor=0d1117)](https://github.com/sponsors/byjg)
[![Build Status](https://github.com/byjg/php-gluo-laravel/actions/workflows/phpunit.yml/badge.svg?branch=master)](https://github.com/byjg/php-gluo-laravel/actions/workflows/phpunit.yml)
[![Opensource ByJG](https://img.shields.io/badge/opensource-byjg-success.svg)](http://opensource.byjg.com)
[![GitHub source](https://img.shields.io/badge/Github-source-informational?logo=github)](https://github.com/byjg/php-gluo-laravel)
[![GitHub license](https://img.shields.io/github/license/byjg/php-gluo-laravel.svg)](https://opensource.byjg.com/license/)
[![GitHub release](https://img.shields.io/github/release/byjg/php-gluo-laravel.svg)](https://github.com/byjg/php-gluo-laravel/releases)

**Gluo** (Esperanto for *glue*) is a family of packages that bind the ByJG components together.
This one is the **Laravel connector**: it adapts those components to Laravel idioms — service
providers, configuration, middleware, artisan commands and test helpers — so you can drop them
into an existing application without writing the plumbing yourself.

| Package                                                        | Role                                                  |
|----------------------------------------------------------------|-------------------------------------------------------|
| [`byjg/gluo`](https://github.com/byjg/php-gluo)                 | Scaffold for a standalone REST API project            |
| [`byjg/gluo-core`](https://github.com/byjg/php-gluo-core)       | The common framework core shared by Gluo projects     |
| **`byjg/gluo-laravel`**                                         | **Connectors for an existing Laravel application**    |

## Pluggable by design

Requiring this package pulls in **no ByJG component at all** — only `illuminate/*`.

You install the components you actually want, and the matching connector activates on its own.
A connector whose component is absent simply stays dormant.

```bash
composer require byjg/gluo-laravel

# Want OpenAPI contract testing? Add the component; the connector wakes up.
composer require byjg/swagger-test
```

### Available connectors

| Connector      | Component                                                              | What it gives you                                                                 |
|----------------|------------------------------------------------------------------------|-----------------------------------------------------------------------------------|
| `openapi`      | [`byjg/swagger-test`](https://github.com/byjg/php-swagger-test)         | Contract testing through the Laravel kernel, plus a runtime validation middleware  |
| `statemachine` | [`byjg/statemachine`](https://github.com/byjg/php-statemachine)         | Machines declared as configuration, bound to your Eloquent models, validated in CI |

More connectors are added over the same mechanism — see [Writing a connector](docs/writing-a-connector.md).

## Quick start

```bash
composer require byjg/gluo-laravel byjg/swagger-test
php artisan gluo:install
```

`gluo:install` publishes `config/gluo.php` and a ready-to-edit
`tests/Feature/ApiContractTest.php`. Point the configuration at your specification:

```dotenv
GLUO_OPENAPI_SPEC=/var/www/openapi.json
```

### Contract testing

Every call is validated against the specification — request body, status code and response body.
No HTTP server is involved: requests go straight through the Laravel kernel, so the whole
middleware stack runs and `actingAs()`, `RefreshDatabase` and the rest keep working.

```php
use ByJG\Gluo\Laravel\ApiTools\Testing\InteractsWithOpenApi;
use Tests\TestCase;

class UserApiTest extends TestCase
{
    use InteractsWithOpenApi;

    public function testCreateUser(): void
    {
        $request = $this->openApiRequest()
            ->withMethod('POST')
            ->withPath('/api/users')
            ->withRequestBody(['name' => 'John Doe', 'email' => 'john@example.com'])
            ->expectStatus(201);

        $this->sendRequest($request);
    }
}
```

If the route returns a field the specification does not declare, omits a required one, or
answers with an unexpected status, the test fails with an exception naming exactly what diverged.

**[Contract testing guide →](docs/contract-testing.md)**

### Runtime validation

The same specification can guard the API at runtime through the `gluo.openapi` middleware:

```php
Route::post('/users', [UserController::class, 'store'])
    ->middleware('gluo.openapi');                    // config defaults

Route::get('/users', [UserController::class, 'index'])
    ->middleware('gluo.openapi:request,response');   // validate both sides
```

A request that violates the contract is rejected with `400` before reaching the controller.
A response that violates it is logged and rejected with `500`, because that is a server-side defect.

**[Runtime validation guide →](docs/runtime-validation.md)**

### State machines

Machines are declared as configuration and bound to the models they govern:

```php
class Order extends Model implements StatefulModel
{
    use HasStateMachine;

    protected $casts = ['status' => OrderState::class];
}
```

```php
DB::transaction(function () use ($order, $data) {
    $order->transitionTo(OrderState::Paid, $data);

    $this->somethingElseThatMayThrow();      // rolls back: the receipt never goes out
});
```

The move is persisted before its action runs, and the action is held until the transaction commits —
so a side effect can never escape a write that was rolled back. Conditions and actions are resolved
through the container, so they are ordinary services with ordinary dependencies, and a
`CanTransitionTo` validation rule turns an illegal move requested by a client into a `422` instead
of an exception.

**[State machine guide →](docs/state-machine.md)**

## Requirements

- PHP `>=8.3 <8.6`
- Laravel `^12.0` or `^13.0`

## Documentation

- [Installation and configuration](docs/installation.md)
- [Contract testing](docs/contract-testing.md)
- [Runtime validation](docs/runtime-validation.md)
- [State machines](docs/state-machine.md)
- [Writing a connector](docs/writing-a-connector.md)

## Tests

```bash
composer update
vendor/bin/phpunit
vendor/bin/psalm
```

----
[Open source ByJG](http://opensource.byjg.com)
