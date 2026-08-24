<?php

namespace ByJGTest\Gluo\Laravel\Fixture;

use ByJG\StateMachine\State;
use ByJG\StateMachine\TransitionActionInterface;
use Override;

/**
 * The side effect of being paid: the thing that must never happen for a
 * transaction that rolled back.
 */
class SendReceipt implements TransitionActionInterface
{
    public function __construct(protected ReceiptLog $log)
    {
    }

    #[Override]
    public function execute(State $from, State $to, ?array $data): void
    {
        $this->log->record("$from -> $to");
    }
}
