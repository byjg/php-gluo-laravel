<?php

namespace ByJG\Gluo\Laravel\Exception;

use RuntimeException;

/**
 * Thrown when a machine is asked for by a name that `gluo.statemachine.machines`
 * does not declare.
 */
class StateMachineNotFoundException extends RuntimeException
{
    /**
     * @param string[] $declared Names the configuration does declare
     */
    public function __construct(string $name, array $declared)
    {
        parent::__construct(
            "There is no state machine named '$name'. `gluo.statemachine.machines` declares: "
            . ($declared === [] ? '(none)' : implode(', ', $declared))
        );
    }
}
