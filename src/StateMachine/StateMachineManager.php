<?php

namespace ByJG\Gluo\Laravel\StateMachine;

use ByJG\Gluo\Laravel\Exception\StateMachineNotFoundException;
use ByJG\StateMachine\FiniteStateMachine;
use ByJG\StateMachine\State;
use ByJG\StateMachine\TransitionException;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use UnitEnum;

/**
 * The machines an application declares, each one built once and by name.
 *
 * `FiniteStateMachine::fromDefinition()` takes a plain array, and a Laravel
 * configuration file is a plain array, so a machine needs no parser and no
 * format this package has to know about — the definition is just configuration.
 *
 * Two things are worth centralising here rather than repeating per machine.
 * The container is handed over as the resolver, so a condition or an action
 * with constructor dependencies is built by Laravel like any other service.
 * And a machine is memoised, because building one validates the whole
 * definition — every state name, every class name, every interface — and that
 * is work worth doing once per process rather than once per use.
 */
class StateMachineManager
{
    /** @var array<string, FiniteStateMachine> Machines already built, by name */
    protected array $machines = [];

    public function __construct(
        protected Container $container,
        protected Config $config,
    ) {
    }

    /**
     * The machine declared under a name, built on first use.
     *
     * @throws StateMachineNotFoundException If the configuration declares no such machine
     * @throws TransitionException If the definition is malformed, names a state the enum
     *                             does not declare, or names a class that cannot be resolved
     */
    public function machine(string $name): FiniteStateMachine
    {
        return $this->machines[$name] ??= $this->build($name);
    }

    /**
     * Every machine name the configuration declares.
     *
     * @return string[]
     */
    public function names(): array
    {
        return array_keys($this->definitions());
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->definitions());
    }

    /**
     * The enum whose cases are the states of a machine.
     *
     * The machine itself answers in state names — uppercased strings — while a
     * model column is cast to the enum. Something has to know which enum that
     * is to get from one to the other, and the definition already says.
     *
     * @return class-string<UnitEnum>
     * @throws StateMachineNotFoundException
     * @throws TransitionException If the definition declares no usable enum
     */
    public function enumOf(string $name): string
    {
        $enum = $this->definition($name)['enum'] ?? null;

        if (!is_string($enum) || !enum_exists($enum)) {
            throw new TransitionException(
                "The machine '$name' must declare the 'enum' naming its states, and it must be an enum."
            );
        }

        /** @var class-string<UnitEnum> $enum */
        return $enum;
    }

    /**
     * The case of a machine's enum that a state refers to.
     *
     * A state name is uppercased by the component, so it cannot be handed back
     * to `Enum::from()` unless the case values happen to be uppercase too. The
     * cases are matched the same way the machine matched them, which is what
     * makes an enum written in any casing work.
     *
     * @throws StateMachineNotFoundException
     * @throws TransitionException If no case of the enum names that state
     */
    public function caseOf(string $name, string|UnitEnum|State $state): UnitEnum
    {
        $enum = $this->enumOf($name);
        $wanted = State::nameOf($state);

        foreach ($enum::cases() as $case) {
            if (State::nameOf($case) === $wanted) {
                return $case;
            }
        }

        throw new TransitionException(
            "'$wanted' is not a state of the machine '$name', whose states are named by $enum"
        );
    }

    /**
     * @throws StateMachineNotFoundException
     * @throws TransitionException
     */
    protected function build(string $name): FiniteStateMachine
    {
        $definition = $this->definition($name);

        // The container is a PSR-11 container, which is exactly the shape the
        // resolver expects, so conditions and actions are ordinary services.
        $machine = FiniteStateMachine::fromDefinition($definition, [$this->container, 'get']);

        if (!empty($definition['throw_on_no_transition'])) {
            $machine->throwErrorIfCannotTransition();
        }

        if (!empty($definition['throw_on_ambiguity'])) {
            $machine->throwErrorIfAmbiguousTransition();
        }

        return $machine;
    }

    /**
     * @return array<string, mixed>
     * @throws StateMachineNotFoundException
     */
    protected function definition(string $name): array
    {
        $definitions = $this->definitions();

        if (!array_key_exists($name, $definitions)) {
            throw new StateMachineNotFoundException($name, array_keys($definitions));
        }

        $definition = $definitions[$name];

        if (!is_array($definition)) {
            throw new StateMachineNotFoundException($name, array_keys($definitions));
        }

        /** @var array<string, mixed> $definition */
        return $definition;
    }

    /**
     * @return array<string, mixed>
     */
    protected function definitions(): array
    {
        $machines = $this->config->get('gluo.statemachine.machines', []);

        /** @var array<string, mixed> */
        return is_array($machines) ? $machines : [];
    }
}
