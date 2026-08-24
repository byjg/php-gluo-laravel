<?php

namespace ByJGTest\Gluo\Laravel\Fixture;

/**
 * The states of the fixture machine.
 *
 * The case values are deliberately lowercase. The component names states in
 * uppercase, so an enum written this way is exactly the one that breaks when a
 * state name is handed straight back to `OrderState::from()` — which is what
 * the connector has to get right for the column and the machine to agree.
 */
enum OrderState: string
{
    case Draft = 'draft';
    case Paid = 'paid';
    case Shipped = 'shipped';
    case Cancelled = 'cancelled';
}
