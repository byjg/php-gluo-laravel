<?php

namespace ByJG\Gluo\Laravel\Connector;

use Illuminate\Support\ServiceProvider;

/**
 * Base class for every Gluo connector.
 *
 * A connector is the glue between one ByJG component and Laravel. Each one is
 * independent: it declares which component it needs, and the umbrella
 * {@see \ByJG\Gluo\Laravel\GluoServiceProvider} only registers it when that
 * component is actually installed and the connector is not disabled in the
 * configuration.
 *
 * That is what makes this package pluggable — requiring `byjg/gluo-laravel`
 * pulls in no ByJG component by itself. You install the components you want,
 * and the matching connectors light up on their own.
 */
abstract class Connector extends ServiceProvider
{
    /**
     * Key used under `gluo.connectors` to enable or disable this connector,
     * and the name of its configuration section.
     */
    abstract public static function name(): string;

    /**
     * Class that ships with the underlying ByJG component. When it cannot be
     * found the component is not installed and the connector stays dormant.
     */
    abstract public static function requires(): string;

    /**
     * Whether the underlying component is present in the application.
     */
    public static function isAvailable(): bool
    {
        return class_exists(static::requires()) || interface_exists(static::requires());
    }

    /**
     * Composer package that provides {@see requires()}, used to build a helpful
     * message when someone enables a connector without its component.
     */
    abstract public static function package(): string;

    /**
     * Tag used to publish this connector's starter files with
     * `vendor:publish`. One tag per connector, so they never collide.
     */
    public static function publishTag(): string
    {
        return 'gluo-' . static::name();
    }

    /**
     * Starter files this connector contributes to the host application, as
     * `source path => target path`.
     *
     * Both `gluo:install` and `vendor:publish --tag=<publishTag()>` read this,
     * so a connector declares its files once and nothing else needs editing.
     *
     * @return array<string, string>
     */
    public function publishables(): array
    {
        return [];
    }

    /**
     * Lines printed after `gluo:install` finishes, telling the user what to do
     * next with this connector.
     *
     * @return string[]
     */
    public function postInstallNotes(): array
    {
        return [];
    }
}
