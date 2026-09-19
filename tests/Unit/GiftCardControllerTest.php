<?php

namespace Tests\Unit;

use App\Http\Controllers\V2\Admin\GiftCardController;
use App\Models\Giftcard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GiftCardControllerTest extends TestCase
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

        Schema::create('v2_plan', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('v2_giftcard', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('template_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->integer('type');
            $table->integer('value')->nullable();
            $table->integer('plan_id')->nullable();
            $table->integer('limit_use')->nullable();
            $table->boolean('enabled')->default(true);
            $table->text('used_user_ids')->nullable();
            $table->integer('used_at')->nullable();
            $table->integer('used_by_user_id')->nullable();
            $table->integer('started_at');
            $table->integer('ended_at');
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }

    public function test_money_template_prices_are_stored_in_cents_without_rescaling(): void
    {
        $controller = new GiftCardController();
        $controller->createTemplate(Request::create('/gift-card', 'POST', [
            'name' => '100 cents',
            'price' => 100,
        ]));

        $giftcard = Giftcard::firstOrFail();
        $this->assertSame(100, (int)$giftcard->value);

        $controller->updateTemplate(Request::create('/gift-card', 'POST', [
            'id' => $giftcard->id,
            'price' => 250,
        ]));

        $this->assertSame(250, (int)$giftcard->fresh()->value);
    }
}
