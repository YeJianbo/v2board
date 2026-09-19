<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicStatusController;
use App\Http\Controllers\V1\Admin\MachineGroupController;
use App\Models\Machine;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MachineGroupingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'cache.default' => 'array',
            'v2board.public_status_enable' => 1,
        ]);
        DB::purge('sqlite');

        Schema::create('v2_machine_group', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->unsignedInteger('sort')->default(0);
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        Schema::create('v2_machine', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('machine_group_id')->nullable();
            $table->string('name');
            $table->string('host')->nullable();
            $table->string('api_token')->unique();
            $table->char('country_code', 2)->nullable();
            $table->string('country_name', 64)->nullable();
            $table->text('status')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->tinyInteger('public_visible')->default(1);
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }

    public function testMachineGroupCrudSortAndDeleteOnlyUngroupsMachines(): void
    {
        $controller = app(MachineGroupController::class);
        $controller->save($this->request(['name' => '台湾机器']));
        $controller->save($this->request(['name' => '香港机器']));

        $taiwanId = (int) DB::table('v2_machine_group')->where('name', '台湾机器')->value('id');
        $hongKongId = (int) DB::table('v2_machine_group')->where('name', '香港机器')->value('id');
        DB::table('v2_machine')->insert([
            'machine_group_id' => $taiwanId,
            'name' => 'TWBGP-WAWO',
            'host' => '192.0.2.10',
            'api_token' => 'test-token',
            'status' => '{}',
            'sort' => 1,
            'public_visible' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $controller->sort($this->request(['ids' => [$hongKongId, $taiwanId]]));
        $this->assertSame(1, (int) DB::table('v2_machine_group')->where('id', $hongKongId)->value('sort'));
        $this->assertSame(2, (int) DB::table('v2_machine_group')->where('id', $taiwanId)->value('sort'));

        $controller->drop($this->request(['id' => $taiwanId]));
        $this->assertDatabaseMissing('v2_machine_group', ['id' => $taiwanId]);
        $this->assertNull(DB::table('v2_machine')->where('name', 'TWBGP-WAWO')->value('machine_group_id'));
        $this->assertDatabaseHas('v2_machine', ['name' => 'TWBGP-WAWO']);
    }

    public function testPublicStatusUsesManualCountryInsteadOfProbeReportedCountry(): void
    {
        DB::table('v2_machine')->insert([
            'machine_group_id' => null,
            'name' => 'Manual location machine',
            'host' => '192.0.2.20',
            'api_token' => 'manual-location-token',
            'country_code' => 'DE',
            'country_name' => '德国',
            'status' => json_encode([
                'reported_at' => time(),
                'country_code' => 'US',
                'country' => '美国',
            ], JSON_UNESCAPED_UNICODE),
            'sort' => 1,
            'public_visible' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $controller = app(PublicStatusController::class);
        $method = new \ReflectionMethod($controller, 'buildMachineStatus');
        $method->setAccessible(true);
        $data = $method->invoke($controller);

        $this->assertSame('德国', $data[0]['country']);
        $this->assertSame('DE', $data[0]['country_code']);
    }

    public function testLocationFallsBackToProbeReportedCountryWithoutManualOverride(): void
    {
        DB::table('v2_machine')->insert([
            'machine_group_id' => null,
            'name' => 'Auto location machine',
            'host' => '192.0.2.30',
            'api_token' => 'auto-location-token',
            'country_code' => null,
            'country_name' => null,
            'status' => json_encode([
                'reported_at' => time(),
                'country_code' => 'tw',
                'country' => '台湾',
            ], JSON_UNESCAPED_UNICODE),
            'sort' => 1,
            'public_visible' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $controller = app(PublicStatusController::class);
        $method = new \ReflectionMethod($controller, 'buildMachineStatus');
        $method->setAccessible(true);
        $data = $method->invoke($controller);

        $this->assertSame('TW', $data[0]['country_code']);
        $this->assertSame('台湾', $data[0]['country']);
    }

    public function testClearingManualLocationRestoresProbeReportedCountry(): void
    {
        $machine = new Machine([
            'country_code' => 'DE',
            'country_name' => '德国',
        ]);
        $status = [
            'country_code' => 'US',
            'country' => '美国',
        ];

        $this->assertSame('manual', $machine->resolveLocation($status)['location_source']);

        $machine->country_code = null;
        $machine->country_name = null;
        $location = $machine->resolveLocation($status);

        $this->assertSame('US', $location['country_code']);
        $this->assertSame('美国', $location['country_name']);
        $this->assertSame('auto', $location['location_source']);
    }

    private function request(array $payload): Request
    {
        return Request::create('/', 'POST', $payload);
    }
}
