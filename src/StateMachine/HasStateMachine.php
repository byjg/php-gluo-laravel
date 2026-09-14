<?php

namespace ByJG\Gluo\Laravel\StateMachine;

use ByJG\StateMachine\FiniteStateMachine;
use ByJG\StateMachine\State;
use ByJG\StateMachine\Transition;
use ByJG\StateMachine\TransitionException;
use BackedEnum;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Binds a model's state column to one of the machines declared in
 * `gluo.statemachine.machines`.
 *
 *     class Order extends Model implements StatefulModel
 *     {
 *         use HasStateMachine;
 *
 *         protected string $stateMachine = 'order';
 *         protected string $stateColumn  = 'status';
 *
 *         protected $casts = ['status' => OrderState::class];
 *     }
 *
 * Both properties are optional: the machine defaults to the snake-cased class
 * name and the column to `status`.
 *
 * What this trait exists for is the two things that are easy to get wrong and
 * have nothing to do with any particular model.
 *
 * The first is **ordering**. A transition action is a side effect — a receipt,
 * a webhook, a charge — and it must run only once the move it belongs to has
 * actually landed. `transitionTo()` therefore persists first and processes
 * after, and does the processing through `Connection::afterCommit()`, which
 * runs the action immediately when no transaction is open, defers it to the
 * commit when one is, and drops it when that transaction rolls back. Written by
 * hand inside `DB::transaction()`, the naive version sends the receipt for an
 * order that was never saved.
 *
 * The second is **naming**. The component uppercases state names, so a machine
 * answers `PAID` whether the enum case is `Paid = 'paid'` or `Paid = 'PAID'`.
 * Handing that answer back to `Enum::from()` therefore works only for enums
 * that happen to be written in uppercase. The state is resolved back to its
 * case through the enum the definition declares instead.
 *
 * @mixin Model
 */
trait HasStateMachine
{
    public function stateMachine(): FiniteStateMachine
    {
        return $this->stateMachineManager()->machine($this->stateMachineName());
    }

    public function currentState(): State
    {
        return $this->stateMachine()->state($this->stateReference());
    }

    /**
     * @return Transition[]
     */
    public function possibleTransitions(): array
    {
        return $this->stateMachine()->possibleTransitions($this->stateReference());
    }

    public function canTransitionTo(
        string|UnitEnum|State $desiredState,
        ?array $data = null,
        string|UnitEnum|null $name = null,
    ): bool {
        return $this->stateMachine()->canTransition($this->stateReference(), $desiredState, $data, $name);
    }

    public function transitionTo(
        string|UnitEnum|State $desiredState,
        ?array $data = null,
        string|UnitEnum|null $name = null,
    ): ?State {
        $state = $this->stateMachine()->transition($this->stateReference(), $desiredState, $data, $name);

        return is_null($state) ? null : $this->commitState($state);
    }

    public function autoTransition(array $data): ?State
    {
        $state = $this->stateMachine()->autoTransitionFrom($this->stateReference(), $data);

        return is_null($state) ? null : $this->commitState($state);
    }

    /**
     * Writes the state reached, then schedules its action for after the commit.
     *
     * @throws TransitionException If the write was vetoed by a model event
     */
    protected function commitState(State $state): State
    {
        $case = $this->stateMachineManager()->caseOf($this->stateMachineName(), $state);

        // The backing value of a backed case and the case name of a pure one:
        // exactly the two things Laravel's enum cast reads back, and what a
        // plain string column wants when there is no cast at all.
        $stored = $case instanceof BackedEnum ? $case->value : $case->name;

        $this->setAttribute($this->stateColumn(), $stored);

        if ($this->save() === false) {
            throw new TransitionException(
                'The move to ' . $state->getState() . ' is allowed but could not be saved: a model '
                . 'event on ' . static::class . ' returned false. The action was not run.'
            );
        }

        $this->getConnection()->afterCommit(function () use ($state): void {
            $state->process();
        });

        return $state;
    }

    /**
     * How the current state is named to the machine.
     *
     * A column cast to the enum hands back a case and an uncast one a string;
     * the machine accepts either, so neither needs converting here.
     *
     * @throws TransitionException If the column holds nothing that names a state
     */
    protected function stateReference(): string|UnitEnum
    {
        $value = $this->getAttribute($this->stateColumn());

        if ($value instanceof UnitEnum || (is_string($value) && $value !== '')) {
            return $value;
        }

        throw new TransitionException(
            static::class . '::' . $this->stateColumn() . ' holds ' . get_debug_type($value)
            . ', which names no state. A model has to be in a state before it can leave one.'
        );
    }

    /**
     * The machine governing this model, named by `$stateMachine` when the model
     * declares one and by its own name otherwise.
     */
    protected function stateMachineName(): string
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        if (property_exists($this, 'stateMachine') && is_string($this->stateMachine)) {
            return $this->stateMachine;
        }

        return Str::snake(class_basename(static::class));
    }

    /**
     * The column holding the state, `status` unless the model says otherwise.
     */
    protected function stateColumn(): string
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        if (property_exists($this, 'stateColumn') && is_string($this->stateColumn)) {
            return $this->stateColumn;
        }

        return 'status';
    }

    protected function stateMachineManager(): StateMachineManager
    {
        return Container::getInstance()->make(StateMachineManager::class);
    }
}
