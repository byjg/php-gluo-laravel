<?php

namespace ByJG\Gluo\Laravel\ApiTools;

use ByJG\ApiTools\Base\Schema;
use ByJG\Gluo\Laravel\ApiTools\Middleware\ValidateOpenApi;
use ByJG\Gluo\Laravel\Connector\Connector;
use ByJG\Gluo\Laravel\Exception\SpecificationNotFoundException;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Override;

/**
 * Connector for [byjg/swagger-test](https://github.com/byjg/php-swagger-test).
 *
 * Binds the OpenAPI specification into the container, exposes the
 * `gluo.openapi` middleware for runtime validation, and publishes an example
 * contract test.
 *
 * Requires `composer require byjg/swagger-test`.
 */
class ApiToolsConnector extends Connector
{
    public const string MIDDLEWARE_ALIAS = 'gluo.openapi';

    #[Override]
    public static function name(): string
    {
        return 'openapi';
    }

    #[Override]
    public static function requires(): string
    {
        return Schema::class;
    }

    #[Override]
    public static function package(): string
    {
        return 'byjg/swagger-test';
    }

    #[Override]
    public function register(): void
    {
        // Parsing the specification is not free, so a single instance is shared
        // by the middleware and by every contract test in the suite.
        $this->app->singleton(Schema::class, function (Application $app): Schema {
            /** @var Config $config */
            $config = $app->make('config');

            return $this->loadSchema($config);
        });
    }

    public function boot(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware(self::MIDDLEWARE_ALIAS, ValidateOpenApi::class);

        if ($this->app->runningInConsole()) {
            $this->publishes($this->publishables(), static::publishTag());
        }
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function publishables(): array
    {
        return [
            dirname(__DIR__, 2) . '/stubs/ApiContractTest.php.stub' => base_path('tests/Feature/ApiContractTest.php'),
        ];
    }

    /**
     * @return string[]
     */
    #[Override]
    public function postInstallNotes(): array
    {
        return [
            'Point `gluo.openapi.spec` (or GLUO_OPENAPI_SPEC) at your openapi.json.',
            'Run `php artisan test --filter=ApiContractTest`.',
            'Optionally guard your routes with the `gluo.openapi` middleware.',
        ];
    }

    /**
     * @throws SpecificationNotFoundException
     */
    protected function loadSchema(Config $config): Schema
    {
        $spec = $config->get('gluo.openapi.spec');

        if (!is_string($spec) || $spec === '' || !is_file($spec)) {
            throw new SpecificationNotFoundException(
                'The OpenAPI specification was not found at ' . var_export($spec, true) . '. '
                . 'Set `gluo.openapi.spec` in config/gluo.php or the GLUO_OPENAPI_SPEC environment variable.'
            );
        }

        return Schema::fromFile($spec, (bool)$config->get('gluo.openapi.allow_null_values', false));
    }

    /**
     * @return string[]
     */
    #[Override]
    public function provides(): array
    {
        return [Schema::class];
    }
}
