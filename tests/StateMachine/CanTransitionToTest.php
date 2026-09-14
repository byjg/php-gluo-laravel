<?php

namespace ByJGTest\Gluo\Laravel\StateMachine;

use ByJG\Gluo\Laravel\StateMachine\Rules\CanTransitionTo;
use ByJGTest\Gluo\Laravel\Fixture\Order;
use ByJGTest\Gluo\Laravel\Fixture\OrderState;
use Illuminate\Support\Facades\Validator;

class CanTransitionToTest extends StateMachineTestCase
{
    public function testAcceptsAStateTheModelCanMoveTo(): void
    {
        $this->gateway()->settle('pay_1');

        $validator = $this->validate('paid', ['payment_id' => 'pay_1']);

        $this->assertTrue($validator->passes());
    }

    public function testAcceptsTheEnumCaseAsReadily(): void
    {
        $this->gateway()->settle('pay_1');

        $validator = $this->validate(OrderState::Paid, ['payment_id' => 'pay_1']);

        $this->assertTrue($validator->passes());
    }

    public function testRefusesAMoveTheGraphDoesNotJoin(): void
    {
        $validator = $this->validate('shipped');

        $this->assertFalse($validator->passes());
        $this->assertSame(
            'The status is not a state this record can move to from DRAFT. Allowed: CANCELLED.',
            $validator->errors()->first('status'),
        );
    }

    /**
     * The condition on DRAFT -> PAID is the reason PAID is missing from the
     * message: a client told it may move somewhere its data is then refused for
     * has been told nothing.
     */
    public function testRefusesAMoveWhoseConditionDoesNotHold(): void
    {
        $validator = $this->validate('paid', ['payment_id' => 'never_settled']);

        $this->assertFalse($validator->passes());
        $this->assertStringContainsString('Allowed: CANCELLED.', $validator->errors()->first('status'));
    }

    public function testRefusesAValueThatNamesNoStateAtAll(): void
    {
        $validator = $this->validate('refunded');

        $this->assertFalse($validator->passes());
        $this->assertStringContainsString('is not a state this record can move to', $validator->errors()->first('status'));
    }

    public function testRefusesAValueThatIsNotAStateNameAtAll(): void
    {
        $validator = $this->validate(['not', 'a', 'state']);

        $this->assertFalse($validator->passes());
        $this->assertSame('The status must name a state.', $validator->errors()->first('status'));
    }

    public function testSaysSoWhenTheModelIsInAFinalState(): void
    {
        $order = Order::query()->create(['status' => OrderState::Cancelled]);

        $validator = Validator::make(
            ['status' => 'paid'],
            ['status' => [new CanTransitionTo($order)]],
        );

        $this->assertFalse($validator->passes());
        $this->assertSame(
            'The status is not a state this record can move to: CANCELLED is a final state.',
            $validator->errors()->first('status'),
        );
    }

    /**
     * A machine built with throwErrorIfCannotTransition() is asking to fail
     * loudly where a move is performed. A validation rule is not that place —
     * it is where the answer is a `422`.
     */
    public function testStillFailsGracefullyWhenTheMachineThrowsOnRefusal(): void
    {
        config()->set('gluo.statemachine.machines.order.throw_on_no_transition', true);

        $validator = $this->validate('shipped');

        $this->assertFalse($validator->passes());
        $this->assertStringContainsString('is not a state this record can move to', $validator->errors()->first('status'));
    }

    protected function validate(mixed $value, ?array $data = null): \Illuminate\Validation\Validator
    {
        $order = Order::query()->create(['status' => OrderState::Draft]);

        /** @var \Illuminate\Validation\Validator $validator */
        $validator = Validator::make(
            ['status' => $value],
            ['status' => [new CanTransitionTo($order, $data)]],
        );

        return $validator;
    }
}
