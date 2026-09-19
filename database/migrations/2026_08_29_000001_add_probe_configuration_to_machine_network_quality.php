<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_machine_network_quality')) {
            return;
        }

        Schema::table('v2_machine_network_quality', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_machine_network_quality', 'probe_type')) {
                $table->string('probe_type', 8)->default('icmp');
            }
            if (!Schema::hasColumn('v2_machine_network_quality', 'target_port')) {
                $table->unsignedSmallInteger('target_port')->default(80);
            }
            if (!Schema::hasColumn('v2_machine_network_quality', 'ip_version')) {
                $table->string('ip_version', 4)->default('auto');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_machine_network_quality')) {
            return;
        }

        Schema::table('v2_machine_network_quality', function (Blueprint $table) {
            foreach (['probe_type', 'target_port', 'ip_version'] as $column) {
                if (Schema::hasColumn('v2_machine_network_quality', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
