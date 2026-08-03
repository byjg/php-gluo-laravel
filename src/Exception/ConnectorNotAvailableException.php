<?php

namespace ByJG\Gluo\Laravel\Exception;

use RuntimeException;

/**
 * Thrown when a connector is explicitly enabled in `gluo.connectors` but the
 * ByJG component it depends on is not installed.
 */
class ConnectorNotAvailableException extends RuntimeException
{
    public function __construct(
        public readonly string $connector,
        public readonly string $package,
    ) {
        parent::__construct(
            "The Gluo connector '$connector' is enabled but '$package' is not installed. "
            . "Run `composer require $package`, or set `gluo.connectors.$connector` to false."
        );
    }
}
