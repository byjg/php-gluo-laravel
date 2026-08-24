<?php

namespace ByJGTest\Gluo\Laravel\Fixture;

/**
 * A collaborator the transition condition cannot build for itself, which is
 * what makes it worth resolving conditions through the container.
 */
class PaymentGateway
{
    /** @var string[] */
    protected array $settled = [];

    public function settle(string $paymentId): void
    {
        $this->settled[] = $paymentId;
    }

    public function isSettled(?string $paymentId): bool
    {
        return !is_null($paymentId) && in_array($paymentId, $this->settled, true);
    }
}
