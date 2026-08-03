<?php

namespace ByJGTest\Gluo\Laravel;

use ByJG\Gluo\Laravel\GluoServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Override;

/**
 * Boots a real Laravel application with the package installed and a handful of
 * routes that back the fixture specification.
 */
abstract class TestCase extends OrchestraTestCase
{
    public const string SPEC = __DIR__ . '/Fixture/openapi.json';

    /**
     * @return array<int, class-string>
     */
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [GluoServiceProvider::class];
    }

    #[Override]
    protected function defineEnvironment($app): void
    {
        $app['config']->set('gluo.openapi.spec', self::SPEC);
    }

    #[Override]
    protected function defineRoutes($router): void
    {
        $this->defineApiRoutes($router);
    }

    protected function defineApiRoutes(Router $router): void
    {
        $router->get('/api/ping', fn() => response()->json(['message' => 'pong']));

        $router->get('/api/echo', fn(Request $request) => response()->json([
            'message' => (string)$request->query('message'),
        ]));

        $router->get('/api/headers', fn(Request $request) => response()->json([
            'token' => (string)$request->header('X-Token', ''),
        ]));

        $router->post('/api/users', fn(Request $request) => response()->json([
            'id' => 1,
            'name' => (string)$request->input('name'),
            'email' => (string)$request->input('email'),
        ], 201));

        // Reads through $request->input(), so it only answers correctly when the
        // urlencoded body actually reached Laravel's request bag.
        $router->post('/api/form', fn(Request $request) => response()->json([
            'name' => (string)$request->input('name'),
            'email' => (string)$request->input('email'),
        ]));

        // Deliberately violates the contract: `message` must be a string.
        $router->get('/api/broken', fn() => response()->json(['message' => 12345]));
    }

    protected function laravelApp(): Application
    {
        return $this->app;
    }
}
