<?php

namespace ByJG\Gluo\Laravel\StateMachine;

use ByJG\Gluo\Laravel\Connector\Connector;
use ByJG\StateMachine\FiniteStateMachine;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Override;

/**
 * Connector for [byjg/statemachine](https://github.com/byjg/php-statemachine).
 *
 * The component needs no adapter to run under Laravel: the container is already
 * a valid resolver for its conditions and actions, a configuration file is
 * already the array its definitions are written as, and a native enum cast
 * already produces the type its every method accepts.
 *
 * So this connector deliberately does not wrap any of that. What it adds is the
 * part an application would otherwise write once per project and get subtly
 * wrong: machines declared as configuration and validated once
 * ({@see StateMachineManager}), a model binding that persists a move before
 * running its side effect and never lets that side effect escape a rolled-back
 * transaction ({@see HasStateMachine}), and a validation rule that turns an
 * illegal move into a `422` instead of an exception
 * ({@see Rules\CanTransitionTo}).
 *
 * Requires `composer require byjg/statemachine`.
 */
class StateMachineConnector extends Connector
{
    #[Override]
    public static function name(): string
    {
        return 'statemachine';
    }

    #[Override]
    public static function requires(): string
    {
        return FiniteStateMachine::class;
    }

    #[Override]
    public static function package(): string
    {
        return 'byjg/statemachine';
    }

    #[Override]
    public function register(): void
    {
        // Shared, because the manager memoises the machines it builds and
        // building one validates its whole definition.
        $this->app->singleton(
            StateMachineManager::class,
            function (Application $app): StateMachineManager {
                /** @var Config $config */
                $config = $app->make('config');

                /** @var Container $container */
                $container = $app;

                return new StateMachineManager($container, $config);
            },
        );
    }

    public function boot(): void
    {
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
            dirname(__DIR__, 2) . '/stubs/StateMachineDefinitionTest.php.stub'
                => base_path('tests/Feature/StateMachineDefinitionTest.php'),
        ];
    }

    /**
     * @return string[]
     */
    #[Override]
    public function postInstallNotes(): array
    {
        return [
            'Declare your machines under `gluo.statemachine.machines` in config/gluo.php.',
            'Add `use HasStateMachine` and `implements StatefulModel` to the models they govern.',
            'Run `php artisan test --filter=StateMachineDefinitionTest` to validate every definition.',
        ];
    }

    /**
     * @return string[]
     */
    #[Override]
    public function provides(): array
    {
        return [StateMachineManager::class];
    }
}
