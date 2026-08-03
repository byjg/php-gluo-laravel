<?php

namespace ByJG\Gluo\Laravel;

use ByJG\Gluo\Laravel\ApiTools\ApiToolsConnector;
use ByJG\Gluo\Laravel\Connector\Connector;
use ByJG\Gluo\Laravel\Console\InstallCommand;
use ByJG\Gluo\Laravel\Exception\ConnectorNotAvailableException;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Entry point of the package: registers the connectors whose ByJG component is
 * installed in the application.
 *
 * Nothing here is mandatory. Require `byjg/gluo-laravel`, then install only the
 * ByJG components you want — each connector activates itself when its component
 * is present, and can be switched off explicitly under `gluo.connectors`.
 *
 * Registered automatically through Laravel package discovery; no manual entry
 * in `bootstrap/providers.php` is required.
 */
class GluoServiceProvider extends ServiceProvider
{
    public const string CONFIG_TAG = 'gluo-config';

    /**
     * Container key holding the connectors that are currently active.
     * Resolved on demand so it always reflects the configuration in force.
     */
    public const string ACTIVE_CONNECTORS = 'gluo.connectors.active';

    /**
     * Every connector shipped by this package.
     *
     * @var array<int, class-string<Connector>>
     */
    protected array $connectors = [
        ApiToolsConnector::class,
    ];

    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'gluo');

        $this->app->bind(self::ACTIVE_CONNECTORS, fn(): array => $this->activeConnectors());

        foreach ($this->activeConnectors() as $connector) {
            $this->app->register($connector);
        }
    }

    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->publishes([$this->configPath() => config_path('gluo.php')], self::CONFIG_TAG);
        $this->commands([InstallCommand::class]);
    }

    /**
     * Connectors that are both enabled in the configuration and backed by an
     * installed component.
     *
     * A connector explicitly enabled without its component is an error worth
     * reporting — silently ignoring it would leave the developer wondering why
     * nothing happens. Left at its default, it simply stays dormant.
     *
     * @return array<int, class-string<Connector>>
     * @throws ConnectorNotAvailableException
     */
    public function activeConnectors(): array
    {
        /** @var Config $config */
        $config = $this->app->make('config');

        $active = [];

        foreach ($this->connectors as $connector) {
            $enabled = $config->get('gluo.connectors.' . $connector::name());

            if ($enabled === false) {
                continue;
            }

            if ($connector::isAvailable()) {
                $active[] = $connector;
                continue;
            }

            if ($enabled === true) {
                throw new ConnectorNotAvailableException($connector::name(), $connector::package());
            }
        }

        return $active;
    }

    protected function configPath(): string
    {
        return __DIR__ . '/../config/gluo.php';
    }
}
