<?php

namespace ByJGTest\Gluo\Laravel\StateMachine;

use ByJGTest\Gluo\Laravel\Fixture\OrderState;
use ByJGTest\Gluo\Laravel\Fixture\PaymentCleared;
use ByJGTest\Gluo\Laravel\Fixture\PaymentGateway;
use ByJGTest\Gluo\Laravel\Fixture\ReceiptLog;
use ByJGTest\Gluo\Laravel\Fixture\SendReceipt;
use ByJGTest\Gluo\Laravel\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Override;

/**
 * A booted application with one machine declared in configuration and the
 * `orders` table the fixture model maps to.
 *
 * The collaborators are bound as singletons so a test can settle a payment or
 * read the receipt log and see the same instances the machine resolved.
 */
abstract class StateMachineTestCase extends TestCase
{
    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('gluo.statemachine.machines', ['order' => static::orderDefinition()]);

        $app->singleton(PaymentGateway::class);
        $app->singleton(ReceiptLog::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('orders', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('status');
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected static function orderDefinition(): array
    {
        return [
            'enum' => OrderState::class,
            'transitions' => [
                [
                    'from' => 'DRAFT',
                    'to' => 'PAID',
                    'condition' => PaymentCleared::class,
                    'action' => SendReceipt::class,
                ],
                ['from' => 'PAID', 'to' => 'SHIPPED'],
                ['from' => ['DRAFT', 'PAID'], 'to' => 'CANCELLED'],
            ],
        ];
    }

    protected function gateway(): PaymentGateway
    {
        return $this->app->make(PaymentGateway::class);
    }

    protected function receipts(): ReceiptLog
    {
        return $this->app->make(ReceiptLog::class);
    }
}
