<?php

namespace ByJG\Gluo\Laravel\ApiTools\Testing;

use ByJG\ApiTools\Base\Schema;
use ByJG\ApiTools\OpenApiValidation;
use ByJG\Gluo\Laravel\ApiTools\LaravelRequester;
use Illuminate\Contracts\Foundation\Application;

/**
 * Adds OpenAPI contract testing to any Laravel test case.
 *
 * The schema is resolved from the container — configure its location once in
 * `config/gluo.php` and every test reads the same specification.
 *
 * <code>
 * class UserApiTest extends Tests\TestCase
 * {
 *     use InteractsWithOpenApi;
 *
 *     public function testCreateUser(): void
 *     {
 *         $request = $this->openApiRequest()
 *             ->withMethod('POST')
 *             ->withPath('/api/users')
 *             ->withRequestBody(['name' => 'John', 'email' => 'john@example.com'])
 *             ->expectStatus(201);
 *
 *         $this->sendRequest($request);
 *     }
 * }
 * </code>
 */
trait InteractsWithOpenApi
{
    use OpenApiValidation;

    /**
     * The specification configured in `gluo.openapi.spec`.
     */
    protected function openApiSchema(): Schema
    {
        /** @var Schema $schema */
        $schema = $this->gluoApplication()->make(Schema::class);

        return $schema;
    }

    /**
     * A requester bound to the application under test, with the schema already
     * attached. Chain the `with*()` / `expect*()` methods on the result and
     * hand it to `sendRequest()`.
     */
    protected function openApiRequest(): LaravelRequester
    {
        $requester = new LaravelRequester($this->gluoApplication());
        $requester->withSchema($this->openApiSchema());

        return $requester;
    }

    /**
     * Resolved through the helper rather than `$this->app` so the trait works
     * in any test case, not only those extending Laravel's TestCase.
     */
    protected function gluoApplication(): Application
    {
        /** @var Application $app */
        $app = app();

        return $app;
    }
}
