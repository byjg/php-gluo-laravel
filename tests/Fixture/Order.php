<?php

namespace ByJGTest\Gluo\Laravel\Fixture;

use ByJG\Gluo\Laravel\StateMachine\HasStateMachine;
use ByJG\Gluo\Laravel\StateMachine\StatefulModel;
use Illuminate\Database\Eloquent\Model;

/**
 * @property OrderState $status
 */
class Order extends Model implements StatefulModel
{
    use HasStateMachine;

    protected string $stateMachine = 'order';

    protected string $stateColumn = 'status';

    public $timestamps = false;

    /** @var string[] */
    protected $fillable = ['status'];

    /** @var array<string, string> */
    protected $casts = ['status' => OrderState::class];
}
