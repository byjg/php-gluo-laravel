<?php

namespace ByJGTest\Gluo\Laravel\Fixture;

use ByJG\Gluo\Laravel\StateMachine\HasStateMachine;
use ByJG\Gluo\Laravel\StateMachine\StatefulModel;
use Illuminate\Database\Eloquent\Model;

/**
 * The same trait with nothing declared: no `$stateMachine`, no `$stateColumn`.
 * It should reach the machine called `shipment` through the column `status`.
 *
 * @property OrderState $status
 */
class Shipment extends Model implements StatefulModel
{
    use HasStateMachine;

    protected $table = 'orders';

    public $timestamps = false;

    /** @var string[] */
    protected $fillable = ['status'];

    /** @var array<string, string> */
    protected $casts = ['status' => OrderState::class];
}
