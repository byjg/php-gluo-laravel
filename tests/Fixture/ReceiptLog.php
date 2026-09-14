<?php

namespace ByJGTest\Gluo\Laravel\Fixture;

/**
 * Records the side effects that actually ran, so a test can assert not only
 * that a transition was allowed but that its action was — or was not —
 * executed.
 */
class ReceiptLog
{
    /** @var string[] */
    protected array $entries = [];

    public function record(string $entry): void
    {
        $this->entries[] = $entry;
    }

    /**
     * @return string[]
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
