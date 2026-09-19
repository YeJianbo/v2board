<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RavelMigrationTest extends TestCase
{
    public function test_migration_preserves_nodes_and_is_repeatable(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        Schema::dropAllTables();
        Schema::create('v2_server_v2node', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });
        DB::table('v2_server_v2node')->insert(['name' => 'existing node']);
        require_once base_path('database/migrations/2026_07_15_000001_add_ravel_control_plane.php');
        $migration = new \AddRavelControlPlane();
        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::hasTable('v2_ravel_credential'));
        $this->assertTrue(Schema::hasColumn('v2_server_v2node', 'ravel_settings'));
        $this->assertSame('existing node', DB::table('v2_server_v2node')->value('name'));
        $this->assertNull(DB::table('v2_server_v2node')->value('ravel_authority'));
    }
}
