<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v2_machine') && !Schema::hasColumn('v2_machine', 'network_quality_targets')) {
            Schema::table('v2_machine', function (Blueprint $table) {
                $table->json('network_quality_targets')->nullable()->after('network_quality_interval');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('v2_machine') && Schema::hasColumn('v2_machine', 'network_quality_targets')) {
            Schema::table('v2_machine', function (Blueprint $table) {
                $table->dropColumn('network_quality_targets');
            });
        }
    }
};
