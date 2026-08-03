<?php

namespace ByJGTest\Gluo\Laravel\ApiTools;

use ByJG\Gluo\Laravel\ApiTools\ApiToolsConnector;
use ByJGTest\Gluo\Laravel\TestCase;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Override;

class ValidateOpenApiTest extends TestCase
{
    #[Override]
    protected function defineApiRoutes(Router $router): void
    {
        parent::defineApiRoutes($router);

        $alias = ApiToolsConnector::MIDDLEWARE_ALIAS;

        $router->post('/api/users', fn(Request $request) => response()->json([
            'id' => 1,
            'name' => (string)$request->input('name'),
            'email' => (string)$request->input('email'),
        ], 201))->middleware($alias);

        $router->get('/api/broken', fn() => response()->json(['message' => 12345]))
            ->middleware("$alias:request,response");

        $router->get('/api/undocumented', fn() => response()->json(['anything' => true]))
            ->middleware($alias);
    }

    public function testLetsAValidRequestThrough(): void
    {
        $response = $this->postJson('/api/users', ['name' => 'John', 'email' => 'john@example.com']);

        $response->assertStatus(201);
        $response->assertJson(['id' => 1, 'name' => 'John']);
    }

    public function testRejectsARequestThatViolatesTheContract(): void
    {
        $response = $this->postJson('/api/users', ['name' => 'John']);

        $response->assertStatus(400);
        $response->assertJsonPath('error', 'The request does not match the API specification.');
        $this->assertStringContainsString("Required property 'email'", (string)$response->json('message'));
    }

    public function testUsesTheConfiguredErrorStatus(): void
    {
        config()->set('gluo.openapi.validation.error_status', 422);

        $this->postJson('/api/users', ['name' => 'John'])->assertStatus(422);
    }

    public function testResponseValidationIsOffByDefault(): void
    {
        // /api/ping carries no middleware; /api/broken opts into response
        // validation explicitly, so the default must not affect other routes.
        $this->assertFalse(config()->get('gluo.openapi.validation.response'));

        $this->getJson('/api/ping')->assertStatus(200);
    }

    public function testCatchesAResponseThatViolatesTheContract(): void
    {
        $response = $this->getJson('/api/broken');

        $response->assertStatus(500);
        $response->assertJsonPath('error', 'The response does not match the API specification.');
    }

    public function testPassesThroughUndocumentedRoutesByDefault(): void
    {
        $this->assertFalse(config()->get('gluo.openapi.validation.strict_paths'));

        $this->getJson('/api/undocumented')->assertStatus(200)->assertJson(['anything' => true]);
    }

    public function testRejectsUndocumentedRoutesWhenStrict(): void
    {
        config()->set('gluo.openapi.validation.strict_paths', true);

        $response = $this->getJson('/api/undocumented');

        $response->assertStatus(400);
        $response->assertJsonPath('error', 'The requested route is not described in the API specification.');
    }

    public function testExplicitModesOverrideTheConfiguration(): void
    {
        // Request validation is disabled globally, but /api/users declares no
        // explicit mode, so it follows the configuration and lets the bad body
        // reach the route.
        config()->set('gluo.openapi.validation.request', false);

        $this->postJson('/api/users', ['name' => 'John'])->assertStatus(201);

        // /api/broken pins `request,response`, so it keeps validating.
        $this->getJson('/api/broken')->assertStatus(500);
    }
}
