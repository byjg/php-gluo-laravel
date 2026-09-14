<?php

namespace ByJG\Gluo\Laravel\StateMachine\Rules;

use ByJG\Gluo\Laravel\StateMachine\StatefulModel;
use ByJG\StateMachine\Transition;
use ByJG\StateMachine\TransitionException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Override;
use UnitEnum;

/**
 * Validates that the state a request asks for is one the model may actually
 * move to.
 *
 *     public function rules(): array
 *     {
 *         return [
 *             'status' => ['required', new CanTransitionTo($this->route('order'))],
 *         ];
 *     }
 *
 * This is the difference between a `422` naming the states the order can reach
 * and a `500` from a transition attempted in the controller. The machine
 * already knows the answer; the rule only asks it at the point where Laravel
 * expects the question to be asked.
 *
 * A value naming no state at all — a typo, a state from another workflow, a
 * state that was removed — fails the same way as a state that exists but cannot
 * be reached. Neither is an error in the application, and the client cannot
 * tell them apart anyway.
 */
class CanTransitionTo implements ValidationRule
{
    /**
     * @param StatefulModel $model The model the requested state applies to
     * @param array|null $data Data the transition conditions read, when the move
     *                         is guarded by one
     * @param string|UnitEnum|null $name Which move is meant, when the same pair of
     *                                   states is joined by more than one
     */
    public function __construct(
        protected StatefulModel $model,
        protected ?array $data = null,
        protected string|UnitEnum|null $name = null,
    ) {
    }

    #[Override]
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) && !$value instanceof UnitEnum) {
            $fail('The :attribute must name a state.');

            return;
        }

        try {
            $allowed = $this->model->canTransitionTo($value, $this->data, $this->name);
        } catch (TransitionException) {
            // Either the value names no state of this machine, or the machine was
            // built with throwErrorIfCannotTransition(). Both mean "no".
            $allowed = false;
        }

        if ($allowed) {
            return;
        }

        $fail($this->reason());
    }

    /**
     * Why the move was refused, in terms of where the model actually is.
     *
     * The states listed are those the same data would be accepted for, not
     * every state the graph joins to this one — a client told it may move to a
     * state that its data then gets refused for has been told nothing. Reading
     * the conditions to find out is safe because they are required to be free
     * of side effects.
     */
    protected function reason(): string
    {
        try {
            $current = $this->model->currentState()->getState();
            $transitions = $this->model->possibleTransitions();
        } catch (TransitionException) {
            return 'The :attribute is not a state this record can move to.';
        }

        $allowed = array_values(array_unique(array_map(
            fn(Transition $transition): string => $transition->getDesiredState()->getState(),
            array_filter(
                $transitions,
                fn(Transition $transition): bool => $transition->runTransitionFunction($this->data),
            ),
        )));

        if ($allowed !== []) {
            return "The :attribute is not a state this record can move to from $current. "
                . 'Allowed: ' . implode(', ', $allowed) . '.';
        }

        return $transitions === []
            ? "The :attribute is not a state this record can move to: $current is a final state."
            : "The :attribute is not a state this record can move to: no move out of $current "
                . 'is allowed for this record.';
    }
}
