<?php

namespace ByJGTest\Gluo\Laravel\StateMachine;

use ByJG\Gluo\Laravel\Exception\StateMachineNotFoundException;
use ByJG\Gluo\Laravel\GluoServiceProvider;
use ByJG\Gluo\Laravel\StateMachine\StateMachineConnector;
use ByJG\Gluo\Laravel\StateMachine\StateMachineManager;
use ByJG\StateMachine\FiniteStateMachine;
use ByJG\StateMachine\TransitionException;
use ByJGTest\Gluo\Laravel\Fixture\OrderState;

class StateMachineManagerTest extends StateMachineTestCase
{
    public function testActivatesTheConnectorWhenTheComponentIsInstalled(): void
    {
        $this->assertTrue(StateMachineConnector::isAvailable());
        $this->assertSame('auto', config('gluo.connectors.statemachine'));

        $provider = $this->app->getProvider(GluoServiceProvider::class);
        $this->assertInstanceOf(GluoServiceProvider::class, $provider);
        $this->assertContains(StateMachineConnector::class, $provider->activeConnectors());
    }

    public function testBuildsTheMachineDeclaredInTheConfiguration(): void
    {
        $machine = $this->manager()->machine('order');

        $this->assertInstanceOf(FiniteStateMachine::class, $machine);
        $this->assertTrue($machine->isInitialState(OrderState::Draft));
        $this->assertTrue($machine->isFinalState(OrderState::Cancelled));
    }

    public function testBuildsEachMachineOnlyOnce(): void
    {
        $manager = $this->manager();

        $this->assertSame(
            $manager->machine('order'),
            $manager->machine('order'),
            'building a machine validates its whole definition, so it is worth doing once',
        );
    }

    public function testSharesTheManagerAcrossTheApplication(): void
    {
        $this->assertSame($this->manager(), $this->app->make(StateMachineManager::class));
    }

    public function testListsTheMachinesItDeclares(): void
    {
        $this->assertSame(['order'], $this->manager()->names());
        $this->assertTrue($this->manager()->has('order'));
        $this->assertFalse($this->manager()->has('invoice'));
    }

    public function testReportsAMachineTheConfigurationDoesNotDeclare(): void
    {
        $this->expectException(StateMachineNotFoundException::class);
        $this->expectExceptionMessageMatches(
            "/There is no state machine named 'invoice'.+declares: order/",
        );

        $this->manager()->machine('invoice');
    }

    public function testResolvesConditionsThroughTheContainer(): void
    {
        // PaymentCleared cannot be built with `new`: it takes a gateway. That it
        // works at all is what proves the container is the resolver, and that
        // the gateway it got is the singleton this test settles the payment on.
        $this->gateway()->settle('pay_1');

        $machine = $this->manager()->machine('order');

        $this->assertTrue($machine->canTransition(OrderState::Draft, OrderState::Paid, ['payment_id' => 'pay_1']));
        $this->assertFalse($machine->canTransition(OrderState::Draft, OrderState::Paid, ['payment_id' => 'pay_2']));
    }

    public function testMapsAStateNameBackToTheCaseThatNamesIt(): void
    {
        // The machine answers PAID; the enum case is Paid = 'paid'. Anything
        // that writes a state to a column has to bridge that.
        $this->assertSame(OrderState::Paid, $this->manager()->caseOf('order', 'PAID'));
        $this->assertSame(OrderState::Paid, $this->manager()->caseOf('order', OrderState::Paid));
        $this->assertSame(OrderState::class, $this->manager()->enumOf('order'));
    }

    public function testReportsAStateNoCaseOfTheEnumNames(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("'REFUNDED' is not a state of the machine 'order'");

        $this->manager()->caseOf('order', 'REFUNDED');
    }

    public function testAppliesTheThrowOnNoTransitionFlag(): void
    {
        config()->set('gluo.statemachine.machines.strict', [
            'enum' => OrderState::class,
            'transitions' => [['from' => 'PAID', 'to' => 'SHIPPED']],
            'throw_on_no_transition' => true,
        ]);

        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage('Cannot transition from DRAFT to SHIPPED');

        $this->manager()->machine('strict')->transition(OrderState::Draft, OrderState::Shipped);
    }

    public function testAppliesTheThrowOnAmbiguityFlag(): void
    {
        config()->set('gluo.statemachine.machines.ambiguous', [
            'enum' => OrderState::class,
            'transitions' => [
                ['from' => 'DRAFT', 'to' => 'PAID'],
                ['from' => 'DRAFT', 'to' => 'CANCELLED'],
            ],
            'throw_on_ambiguity' => true,
        ]);

        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage('Ambiguous transition from DRAFT');

        $this->manager()->machine('ambiguous')->autoTransitionFrom(OrderState::Draft, []);
    }

    public function testKeepsTheFirstMatchWhenAmbiguityIsNotFlagged(): void
    {
        config()->set('gluo.statemachine.machines.relaxed', [
            'enum' => OrderState::class,
            'transitions' => [
                ['from' => 'DRAFT', 'to' => 'PAID'],
                ['from' => 'DRAFT', 'to' => 'CANCELLED'],
            ],
        ]);

        $state = $this->manager()->machine('relaxed')->autoTransitionFrom(OrderState::Draft, []);

        $this->assertNotNull($state);
        $this->assertSame('PAID', $state->getState());
    }

    public function testReportsADefinitionThatNamesNoEnum(): void
    {
        config()->set('gluo.statemachine.machines.broken', [
            'transitions' => [['from' => 'DRAFT', 'to' => 'PAID']],
        ]);

        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("must declare the 'enum'");

        $this->manager()->machine('broken');
    }

    public function testReportsADefinitionNamingAStateTheEnumDoesNotDeclare(): void
    {
        config()->set('gluo.statemachine.machines.broken', [
            'enum' => OrderState::class,
            'transitions' => [['from' => 'DRAFT', 'to' => 'REFUNDED']],
        ]);

        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("'REFUNDED' is not a state of this machine");

        $this->manager()->machine('broken');
    }

    protected function manager(): StateMachineManager
    {
        return $this->app->make(StateMachineManager::class);
    }
}
