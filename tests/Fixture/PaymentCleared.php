<?php

namespace ByJGTest\Gluo\Laravel\Fixture;

use ByJG\StateMachine\TransitionConditionInterface;
use Override;

/**
 * A condition with a constructor dependency, which only builds when the machine
 * resolves it through the container rather than with `new`.
 */
class PaymentCleared implements TransitionConditionInterface
{
    public function __construct(protected PaymentGateway $gateway)
    {
    }

    #[Override]
    public function canTransition(?array $data): bool
    {
        $paymentId = $data['payment_id'] ?? null;

        return is_string($paymentId) && $this->gateway->isSettled($paymentId);
    }
}
