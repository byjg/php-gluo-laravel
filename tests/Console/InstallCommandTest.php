<?php

namespace ByJGTest\Gluo\Laravel\Console;

use ByJG\Gluo\Laravel\ApiTools\ApiToolsConnector;
use ByJG\Gluo\Laravel\StateMachine\StateMachineConnector;
use ByJGTest\Gluo\Laravel\TestCase;
use Illuminate\Filesystem\Filesystem;
use Override;

class InstallCommandTest extends TestCase
{
    private Filesystem $files;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem();
        $this->removePublishedFiles();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->removePublishedFiles();

        parent::tearDown();
    }

    public function testPublishesTheConfigurationAndTheExampleTest(): void
    {
        $this->artisan('gluo:install')->assertSuccessful();

        $this->assertFileExists(config_path('gluo.php'));
        $this->assertFileExists($this->testPath());

        $published = $this->files->get($this->testPath());
        $this->assertStringContainsString('use ByJG\Gluo\Laravel\ApiTools\Testing\InteractsWithOpenApi;', $published);
        $this->assertStringContainsString('class ApiContractTest', $published);
    }

    public function testKeepsExistingFilesUntouched(): void
    {
        $this->files->ensureDirectoryExists(dirname($this->testPath()));
        $this->files->put($this->testPath(), '<?php // mine');

        $this->artisan('gluo:install')
            ->expectsOutputToContain('already exists')
            ->assertSuccessful();

        $this->assertSame('<?php // mine', $this->files->get($this->testPath()));
    }

    public function testOverwritesWithTheForceFlag(): void
    {
        $this->files->ensureDirectoryExists(dirname($this->testPath()));
        $this->files->put($this->testPath(), '<?php // mine');

        $this->artisan('gluo:install', ['--force' => true])->assertSuccessful();

        $this->assertStringContainsString('class ApiContractTest', $this->files->get($this->testPath()));
    }

    public function testSkipsTheExampleTestWhenTheConnectorIsDisabled(): void
    {
        config()->set('gluo.connectors.openapi', false);

        $this->artisan('gluo:install')->assertSuccessful();

        $this->assertFileExists(config_path('gluo.php'));
        $this->assertFileDoesNotExist($this->testPath());
    }

    public function testSaysSoWhenEveryConnectorIsDisabled(): void
    {
        config()->set('gluo.connectors.openapi', false);
        config()->set('gluo.connectors.statemachine', false);

        $this->artisan('gluo:install')
            ->expectsOutputToContain('No connectors are active')
            ->assertSuccessful();

        $this->assertFileExists(config_path('gluo.php'));
        $this->assertFileDoesNotExist($this->testPath());
        $this->assertFileDoesNotExist($this->stateMachineTestPath());
    }

    public function testEachConnectorPublishesItsOwnStarterFiles(): void
    {
        $this->artisan('gluo:install')->assertSuccessful();

        $this->assertFileExists($this->testPath());
        $this->assertFileExists($this->stateMachineTestPath());
    }

    public function testTheStateMachineConnectorDeclaresItsOwnPublishables(): void
    {
        $connector = new StateMachineConnector($this->app);

        $this->assertSame('gluo-statemachine', StateMachineConnector::publishTag());
        $this->assertSame([$this->stateMachineTestPath()], array_values($connector->publishables()));
        $this->assertNotEmpty($connector->postInstallNotes());
    }

    public function testInstallsOnlyTheNamedConnector(): void
    {
        $this->artisan('gluo:install', ['--connector' => ['openapi']])->assertSuccessful();

        $this->assertFileExists($this->testPath());
    }

    public function testReportsAConnectorThatIsNotActive(): void
    {
        $this->artisan('gluo:install', ['--connector' => ['does-not-exist']])
            ->expectsOutputToContain("Connector 'does-not-exist' is not active")
            ->assertSuccessful();

        // The config is still published, but no connector files are.
        $this->assertFileExists(config_path('gluo.php'));
        $this->assertFileDoesNotExist($this->testPath());
    }

    public function testPublishesThroughTheConnectorsOwnTag(): void
    {
        $this->artisan('vendor:publish', ['--tag' => ApiToolsConnector::publishTag()])->assertSuccessful();

        $this->assertFileExists($this->testPath());
    }

    public function testTheConnectorDeclaresItsOwnPublishables(): void
    {
        $connector = new ApiToolsConnector($this->app);

        $this->assertSame('gluo-openapi', ApiToolsConnector::publishTag());
        $this->assertSame([$this->testPath()], array_values($connector->publishables()));
        $this->assertNotEmpty($connector->postInstallNotes());
    }

    private function testPath(): string
    {
        return base_path('tests/Feature/ApiContractTest.php');
    }

    private function stateMachineTestPath(): string
    {
        return base_path('tests/Feature/StateMachineDefinitionTest.php');
    }

    private function removePublishedFiles(): void
    {
        $this->files->delete(config_path('gluo.php'));
        $this->files->delete($this->testPath());
        $this->files->delete($this->stateMachineTestPath());
    }
}
