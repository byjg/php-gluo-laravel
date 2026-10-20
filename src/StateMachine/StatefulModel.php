<?php

namespace ByJG\Gluo\Laravel\StateMachine;

use ByJG\StateMachine\FiniteStateMachine;
use ByJG\StateMachine\State;
use ByJG\StateMachine\Transition;
use UnitEnum;

/**
 * A model whose state column is governed by a machine.
 *
 * {@see HasStateMachine} implements every method of this interface, so a model
 * declares the interface and uses the trait, the way Laravel pairs
 * `MustVerifyEmail` with its trait. Anything that has to accept "a model with a
 * state" — {@see Rules\CanTransitionTo}, a controller, a job — types against
 * this rather than against a trait, which PHP cannot type against.
 */
interface StatefulModel
{
    /**
     * The machine governing this model's state column.
     */
    public function stateMachine(): FiniteStateMachine;

    /**
     * The state the model is in right now, read from its column.
     */
    public function currentState(): State;

    /**
     * Every move leaving the current state, whether or not its condition holds.
     *
     * @return Transition[]
     */
    public function possibleTransitions(): array;

    /**
     * Whether the model may move to a state, given the data the conditions read.
     */
    public function canTransitionTo(
        string|UnitEnum|State $desiredState,
        ?array $data = null,
        string|UnitEnum|null $name = null,
    ): bool;

    /**
     * Moves the model to a state and persists it, or returns null when the move
     * is not allowed.
     */
    public function transitionTo(
        string|UnitEnum|State $desiredState,
        ?array $data = null,
        string|UnitEnum|null $name = null,
    ): ?State;

    /**
     * Moves the model to whichever state the data allows, or returns null when
     * the data allows none.
     */
    public function autoTransition(array $data): ?State;
}
