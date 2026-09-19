<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v2_machine')) {
            Schema::table('v2_machine', function (Blueprint $table) {
                if (!Schema::hasColumn('v2_machine', 'network_quality_enabled')) {
                    $table->tinyInteger('network_quality_enabled')->default(0)->after('probe_auto_update');
                }
                if (!Schema::hasColumn('v2_machine', 'network_quality_interval')) {
                    $table->unsignedInteger('network_quality_interval')->default(300)->after('network_quality_enabled');
                }
            });
        }

        if (!Schema::hasTable('v2_machine_network_quality')) {
            Schema::create('v2_machine_network_quality', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('machine_id');
                $table->string('target_key', 32);
                $table->string('target_name', 64);
                $table->string('target_host', 191);
                $table->unsignedSmallInteger('sent')->default(0);
                $table->unsignedSmallInteger('received')->default(0);
                $table->decimal('packet_loss', 5, 2)->default(100);
                $table->decimal('latency_min', 10, 3)->nullable();
                $table->decimal('latency_avg', 10, 3)->nullable();
                $table->decimal('latency_max', 10, 3)->nullable();
                $table->unsignedInteger('recorded_at');
                $table->unsignedInteger('created_at');
                $table->index(['machine_id', 'recorded_at'], 'idx_machine_quality_time');
                $table->index(['machine_id', 'target_key', 'recorded_at'], 'idx_machine_quality_target_time');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_machine_network_quality');

        if (Schema::hasTable('v2_machine')) {
            Schema::table('v2_machine', function (Blueprint $table) {
                foreach (['network_quality_interval', 'network_quality_enabled'] as $column) {
                    if (Schema::hasColumn('v2_machine', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
