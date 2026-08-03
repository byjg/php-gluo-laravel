<?php

namespace ByJGTest\Gluo\Laravel;

use ByJG\Gluo\Laravel\Connector\Connector;
use Override;

/**
 * A connector whose component is deliberately absent, used to exercise the
 * pluggable activation rules.
 */
class MissingComponentConnector extends Connector
{
    #[Override]
    public static function name(): string
    {
        return 'openapi';
    }

    #[Override]
    public static function requires(): string
    {
        return 'ByJG\\NotInstalled\\Component';
    }

    #[Override]
    public static function package(): string
    {
        return 'byjg/not-installed';
    }

    public function register(): void
    {
    }
}
