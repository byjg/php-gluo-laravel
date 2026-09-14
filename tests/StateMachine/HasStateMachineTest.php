<?php

namespace ByJGTest\Gluo\Laravel\StateMachine;

use ByJG\Gluo\Laravel\Exception\StateMachineNotFoundException;
use ByJG\StateMachine\TransitionException;
use ByJGTest\Gluo\Laravel\Fixture\Order;
use ByJGTest\Gluo\Laravel\Fixture\OrderState;
use ByJGTest\Gluo\Laravel\Fixture\Shipment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class HasStateMachineTest extends StateMachineTestCase
{
    public function testReadsTheCurrentStateFromTheColumn(): void
    {
        $order = $this->order();

        $this->assertSame('DRAFT', $order->currentState()->getState());
    }

    public function testListsTheMovesLeavingTheCurrentState(): void
    {
        $targets = array_map(
            fn($transition): string => $transition->getDesiredState()->getState(),
            $this->order()->possibleTransitions(),
        );

        $this->assertSame(['PAID', 'CANCELLED'], $targets);
    }

    public function testAnswersWhetherAMoveIsAllowed(): void
    {
        $order = $this->order();
        $this->gateway()->settle('pay_1');

        $this->assertTrue($order->canTransitionTo(OrderState::Paid, ['payment_id' => 'pay_1']));
        $this->assertFalse($order->canTransitionTo(OrderState::Paid, ['payment_id' => 'pay_2']));
        $this->assertFalse($order->canTransitionTo(OrderState::Shipped));
    }

    public function testPersistsTheStateItMovesTo(): void
    {
        $order = $this->order();
        $this->gateway()->settle('pay_1');

        $state = $order->transitionTo(OrderState::Paid, ['payment_id' => 'pay_1']);

        $this->assertNotNull($state);
        $this->assertSame('PAID', $state->getState());
        $this->assertSame(OrderState::Paid, $order->refresh()->status);
    }

    /**
     * The machine names states in uppercase and this enum is backed by
     * lowercase values, so the column can only round-trip if the state was
     * resolved back to its case instead of being written as it was named.
     */
    public function testWritesTheValueTheEnumCastReadsBack(): void
    {
        $order = $this->order();
        $this->gateway()->settle('pay_1');

        $order->transitionTo(OrderState::Paid, ['payment_id' => 'pay_1']);

        $this->assertSame('paid', DB::table('orders')->where('id', $order->id)->value('status'));
        $this->assertSame(OrderState::Paid, Order::query()->find($order->id)?->status);
    }

    public function testRunsTheActionOfTheTransitionItTook(): void
    {
        $order = $this->order();
        $this->gateway()->settle('pay_1');

        $order->transitionTo(OrderState::Paid, ['payment_id' => 'pay_1']);

        $this->assertSame(['DRAFT -> PAID'], $this->receipts()->entries());
    }

    public function testLeavesTheModelAloneWhenTheMoveIsNotAllowed(): void
    {
        $order = $this->order();

        $this->assertNull($order->transitionTo(OrderState::Paid, ['payment_id' => 'never_settled']));
        $this->assertSame(OrderState::Draft, $order->refresh()->status);
        $this->assertSame([], $this->receipts()->entries());
    }

    public function testMovesToWhicheverStateTheDataAllows(): void
    {
        $order = $this->order();
        $this->gateway()->settle('pay_1');

        $state = $order->autoTransition(['payment_id' => 'pay_1']);

        $this->assertNotNull($state);
        $this->assertSame('PAID', $state->getState());
        $this->assertSame(OrderState::Paid, $order->refresh()->status);
    }

    public function testFallsThroughToTheUnconditionalMoveWhenTheDataMatchesNothingElse(): void
    {
        // DRAFT -> PAID is guarded and DRAFT -> CANCELLED is not, so an order
        // whose payment never cleared auto-transitions to CANCELLED.
        $state = $this->order()->autoTransition(['payment_id' => 'never_settled']);

        $this->assertNotNull($state);
        $this->assertSame('CANCELLED', $state->getState());
    }

    public function testHoldsTheActionUntilTheTransactionCommits(): void
    {
        $order = $this->order();
        $this->gateway()->settle('pay_1');

        DB::transaction(function () use ($order): void {
            $order->transitionTo(OrderState::Paid, ['payment_id' => 'pay_1']);

            $this->assertSame(
                [],
                $this->receipts()->entries(),
                'the receipt must not be sent while the write can still be rolled back',
            );
        });

        $this->assertSame(['DRAFT -> PAID'], $this->receipts()->entries());
        $this->assertSame(OrderState::Paid, $order->refresh()->status);
    }

    /**
     * The hazard the trait exists for: written by hand, the action runs inside
     * the closure and the receipt goes out for an order that was never paid.
     */
    public function testNeverRunsTheActionOfATransactionThatRolledBack(): void
    {
        $order = $this->order();
        $this->gateway()->settle('pay_1');

        try {
            DB::transaction(function () use ($order): void {
                $order->transitionTo(OrderState::Paid, ['payment_id' => 'pay_1']);

                throw new RuntimeException('something later in the closure failed');
            });

            $this->fail('the transaction was expected to roll back');
        } catch (RuntimeException $exception) {
            $this->assertSame('something later in the closure failed', $exception->getMessage());
        }

        $this->assertSame([], $this->receipts()->entries());
        $this->assertSame(OrderState::Draft, $order->refresh()->status);
    }

    public function testReportsAModelThatIsInNoState(): void
    {
        $order = new Order();

        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage('holds null, which names no state');

        $order->currentState();
    }

    /**
     * A model that declares neither property reaches the machine named after
     * itself, through a column called `status`.
     */
    public function testNamesTheMachineAndColumnAfterConventionWhenNothingIsDeclared(): void
    {
        config()->set('gluo.statemachine.machines.shipment', static::orderDefinition());

        $shipment = new Shipment(['status' => OrderState::Draft]);

        $this->assertSame('DRAFT', $shipment->currentState()->getState());
    }

    public function testReportsAModelWhoseMachineIsNotDeclared(): void
    {
        $shipment = new Shipment(['status' => OrderState::Draft]);

        $this->expectException(StateMachineNotFoundException::class);
        $this->expectExceptionMessage("There is no state machine named 'shipment'");

        $shipment->stateMachine();
    }

    protected function order(OrderState $state = OrderState::Draft): Order
    {
        return Order::query()->create(['status' => $state]);
    }
}
