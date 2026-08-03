<?php

namespace ByJGTest\Gluo\Laravel;

use ByJG\ApiTools\Base\Schema;
use ByJG\ApiTools\OpenApi\OpenApiSchema;
use ByJG\Gluo\Laravel\ApiTools\ApiToolsConnector;
use ByJG\Gluo\Laravel\ApiTools\Middleware\ValidateOpenApi;
use ByJG\Gluo\Laravel\Exception\ConnectorNotAvailableException;
use ByJG\Gluo\Laravel\Exception\SpecificationNotFoundException;
use ByJG\Gluo\Laravel\GluoServiceProvider;
use Illuminate\Routing\Router;

class GluoServiceProviderTest extends TestCase
{
    public function testMergesTheDefaultConfiguration(): void
    {
        $this->assertSame('auto', config('gluo.connectors.openapi'));
        $this->assertTrue(config('gluo.openapi.validation.request'));
        $this->assertSame(400, config('gluo.openapi.validation.error_status'));
    }

    public function testActivatesTheConnectorWhenItsComponentIsInstalled(): void
    {
        $this->assertTrue(ApiToolsConnector::isAvailable());
        $this->assertContains(ApiToolsConnector::class, $this->gluoProvider()->activeConnectors());
    }

    public function testSkipsAConnectorDisabledInTheConfiguration(): void
    {
        config()->set('gluo.connectors.openapi', false);

        $this->assertNotContains(ApiToolsConnector::class, $this->gluoProvider()->activeConnectors());
    }

    public function testReportsAConnectorRequiredWithoutItsComponent(): void
    {
        config()->set('gluo.connectors.openapi', true);

        $provider = new class ($this->app) extends GluoServiceProvider {
            protected array $connectors = [MissingComponentConnector::class];
        };

        $this->expectException(ConnectorNotAvailableException::class);
        $this->expectExceptionMessage('composer require byjg/not-installed');

        $provider->activeConnectors();
    }

    public function testStaysDormantWhenAComponentIsMissingAndTheConnectorIsOnAuto(): void
    {
        $provider = new class ($this->app) extends GluoServiceProvider {
            protected array $connectors = [MissingComponentConnector::class];
        };

        $this->assertSame([], $provider->activeConnectors());
    }

    public function testBindsTheSpecificationAsASharedInstance(): void
    {
        $schema = $this->app->make(Schema::class);

        $this->assertInstanceOf(OpenApiSchema::class, $schema);
        $this->assertSame($schema, $this->app->make(Schema::class), 'the schema must be parsed only once');
    }

    public function testFailsWithAClearMessageWhenTheSpecificationIsMissing(): void
    {
        $this->app->forgetInstance(Schema::class);
        config()->set('gluo.openapi.spec', '/does/not/exist.json');

        $this->expectException(SpecificationNotFoundException::class);
        $this->expectExceptionMessage('GLUO_OPENAPI_SPEC');

        $this->app->make(Schema::class);
    }

    public function testRegistersTheMiddlewareAlias(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $this->assertSame(
            ValidateOpenApi::class,
            $router->getMiddleware()[ApiToolsConnector::MIDDLEWARE_ALIAS] ?? null
        );
    }

    protected function gluoProvider(): GluoServiceProvider
    {
        $provider = $this->app->getProvider(GluoServiceProvider::class);
        $this->assertInstanceOf(GluoServiceProvider::class, $provider);

        return $provider;
    }
}
