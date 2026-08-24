---
sidebar_position: 4
---

# State machines

The `statemachine` connector binds [`byjg/statemachine`](https://github.com/byjg/php-statemachine)
to Laravel: machines declared as configuration, bound to your Eloquent models, and validated in CI.

```bash
composer require byjg/gluo-laravel byjg/statemachine
php artisan gluo:install
```

## What this connector is for

The component needs no adapter to run under Laravel, and its own documentation says so. The
container is already a valid resolver for its conditions and actions, a configuration file is
already the array a definition is written as, and a native enum cast already produces the type
every method of a machine accepts. Wiring one up by hand is four lines in a service provider.

This connector exists for what those four lines leave to you, all of which is the same in every
project that uses the component:

- **The transaction hazard.** A transition action is a side effect — a receipt, a webhook, a
  charge — and it must run only after the move it belongs to has actually landed. Inside
  `DB::transaction()` "after the write" is not "after the commit", so the naive version sends the
  receipt for an order that then rolls back.
- **The naming mismatch.** The component names states in uppercase. `OrderState::from()` therefore
  only works on enums whose case values happen to be uppercase, and silently breaks on the ones
  that aren't.
- **Several machines.** One hand-written singleton per machine is one place per machine to get the
  resolver wrong.
- **An illegal move arriving from a client**, which is a `422`, not an exception.

## The states

A machine is defined by an enum, and its cases are exactly the states that exist:

```php
namespace App\Enums;

enum OrderState: string
{
    case Draft     = 'draft';
    case Paid      = 'paid';
    case Shipped   = 'shipped';
    case Cancelled = 'cancelled';
}
```

The case values are yours to write however you like. The connector resolves state names back to
cases through this enum, so `'draft'`, `'DRAFT'` and `Draft` all mean the same state.

## The definition

Machines are declared under `gluo.statemachine.machines` in `config/gluo.php`:

```php
'statemachine' => [

    'machines' => [

        'order' => [
            'enum' => App\Enums\OrderState::class,
            'transitions' => [
                ['from' => 'DRAFT', 'to' => 'PAID',
                 'condition' => App\Fsm\PaymentCleared::class,
                 'action'    => App\Fsm\SendReceipt::class],

                ['from' => 'PAID', 'to' => 'SHIPPED',
                 'condition' => App\Fsm\StockReserved::class],

                ['from' => ['DRAFT', 'PAID'], 'to' => 'CANCELLED'],
            ],
        ],
    ],
],

```

| Key | Meaning |
|---|---|
| `enum` | Required. The enum whose cases are the states. Every `from` and `to` is checked against it. |
| `transitions[].from` | A state, or a list of them to declare the same move out of several. |
| `transitions[].to` | The state reached. |
| `transitions[].condition` | Optional `TransitionConditionInterface` class. Resolved through the container, so it may have constructor dependencies. |
| `transitions[].action` | Optional `TransitionActionInterface` class, resolved the same way. |
| `transitions[].name` | Required only when the same pair of states is joined more than once — paid by PIX and paid by card are two moves, not one declared twice. |
| `throw_on_no_transition` | Raise instead of returning `null` when a move is not allowed. Default `false`. |
| `throw_on_ambiguity` | Raise when the data matches more than one transition, instead of taking the first. Default `false`. |

Conditions and actions are ordinary services:

```php
namespace App\Fsm;

class PaymentCleared implements TransitionConditionInterface
{
    public function __construct(private PaymentGateway $gateway)
    {
    }

    public function canTransition(?array $data): bool
    {
        return $this->gateway->isSettled($data['payment_id'] ?? null);
    }
}
```

## Using a machine directly

```php
use ByJG\Gluo\Laravel\StateMachine\StateMachineManager;

$machine = app(StateMachineManager::class)->machine('order');

$machine->canTransition(OrderState::Draft, OrderState::Paid, ['payment_id' => $id]);
```

Each machine is built once per process. Building it validates the entire definition — every state
name against the enum, every class name against its interface — so a mistake surfaces at startup
rather than on the one transition nobody exercised.

## Binding it to a model

```php
use ByJG\Gluo\Laravel\StateMachine\HasStateMachine;
use ByJG\Gluo\Laravel\StateMachine\StatefulModel;

class Order extends Model implements StatefulModel
{
    use HasStateMachine;

    protected string $stateMachine = 'order';    // optional: defaults to `order`
    protected string $stateColumn  = 'status';   // optional: defaults to `status`

    protected $casts = ['status' => OrderState::class];
}
```

Both properties are optional. The machine defaults to the snake-cased class name and the column to
`status`, so a model called `Order` governed by a machine called `order` declares neither.

The interface is what lets other code — the validation rule, a controller, a job — accept "a model
with a state" without typing against a trait, which PHP cannot do. The trait implements every
method of it.

```php
$order->currentState();                                   // State: DRAFT
$order->possibleTransitions();                            // Transition[]
$order->canTransitionTo(OrderState::Paid, $data);         // bool

$order->transitionTo(OrderState::Paid, $data);            // ?State — persists, then acts
$order->autoTransition($data);                            // ?State — whichever move the data allows
```

`transitionTo()` and `autoTransition()` return `null` when the move is not allowed, and leave the
model untouched. Nothing is written and no action runs.

### Ordering, and why it is not your problem here

When a move is allowed, the trait writes the new state and only then runs the transition's action —
through `Connection::afterCommit()`, which means:

| Context | When the action runs |
|---|---|
| No transaction open | Immediately after the write |
| Inside `DB::transaction()` | After the commit |
| Transaction rolls back | Never |

So this is safe, and needs nothing else from you:

```php
DB::transaction(function () use ($order, $data) {
    $order->transitionTo(OrderState::Paid, $data);

    $this->somethingElseThatMayThrow();      // rolls back: no receipt goes out
});
```

If the side effect is a queued job, `dispatch(...)->afterCommit()` inside the action expresses the
same guarantee for the job itself.

## Validating a requested state

A client asking for a state it cannot reach is a bad request, not a server error:

```php
use ByJG\Gluo\Laravel\StateMachine\Rules\CanTransitionTo;

public function rules(): array
{
    return [
        'status' => ['required', new CanTransitionTo($this->route('order'), ['payment_id' => $this->input('payment_id')])],
    ];
}
```

```json
{
  "message": "The status is not a state this record can move to from DRAFT. Allowed: CANCELLED."
}
```

The states listed are the ones this request's data would actually be accepted for, not every state
the graph joins to the current one — being told you may move somewhere your data then gets refused
for is being told nothing. A value naming no state at all fails the same way, so a typo or a state
from another workflow never reaches the controller.

## Validating the definition in CI

`gluo:install` publishes `tests/Feature/StateMachineDefinitionTest.php`, which builds every machine
you declare and checks that no state is stranded outside its graph:

```bash
php artisan test --filter=StateMachineDefinitionTest
```

It needs no fixtures and no database, and it fails the moment a definition and the classes it names
drift apart — a renamed condition, a removed enum case, a state added to the enum and forgotten in
the transitions.

## What is deliberately not here

- **No state column migration.** Which table, which column and which default are yours.
- **No events.** A transition action already is the hook for "when this move happens", and it is
  declared next to the move it belongs to.
- **No replacement for the component's API.** `stateMachine()` hands you the `FiniteStateMachine`
  itself; everything the component can do it can still do.
