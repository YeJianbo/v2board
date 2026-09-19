<?php

namespace Tests\Unit;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderServiceIdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('balance')->default(0);
            $table->integer('created_at')->default(0);
            $table->integer('updated_at')->default(0);
        });
        Schema::create('v2_coupon', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('limit_use')->nullable();
            $table->integer('created_at')->default(0);
            $table->integer('updated_at')->default(0);
        });
        Schema::create('v2_order', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('coupon_id')->nullable();
            $table->string('trade_no')->unique();
            $table->integer('type')->default(0);
            $table->integer('status')->default(0);
            $table->integer('total_amount')->default(0);
            $table->integer('balance_amount')->default(0);
            $table->integer('created_at')->default(0);
            $table->integer('updated_at')->default(0);
        });
    }

    public function test_deposit_order_is_only_applied_once(): void
    {
        $user = User::create(['balance' => 0]);
        $order = Order::create([
            'user_id' => $user->id,
            'trade_no' => 'deposit-order',
            'type' => 9,
            'status' => 1,
            'total_amount' => 500,
        ]);

        $service = new OrderService($order);
        $this->assertTrue($service->open());
        $this->assertTrue($service->open());

        $this->assertSame(500, (int)$user->fresh()->balance);
        $this->assertSame(3, (int)$order->fresh()->status);
    }

    public function test_cancelling_twice_refunds_balance_and_coupon_only_once(): void
    {
        $user = User::create(['balance' => 100]);
        $coupon = Coupon::create(['limit_use' => 4]);
        $order = Order::create([
            'user_id' => $user->id,
            'coupon_id' => $coupon->id,
            'trade_no' => 'cancel-order',
            'status' => 0,
            'balance_amount' => 25,
        ]);

        $service = new OrderService($order);
        $this->assertTrue($service->cancel());
        $this->assertTrue($service->cancel());

        $this->assertSame(125, (int)$user->fresh()->balance);
        $this->assertSame(5, (int)$coupon->fresh()->limit_use);
        $this->assertSame(2, (int)$order->fresh()->status);
    }
}
