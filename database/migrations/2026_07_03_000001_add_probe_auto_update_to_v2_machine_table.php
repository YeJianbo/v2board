<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_machine')) {
            return;
        }

        Schema::table('v2_machine', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_machine', 'probe_auto_update')) {
                $table->tinyInteger('probe_auto_update')->default(0)->after('relay_rules');
            }
        });

        DB::table('v2_machine')
            ->whereNull('probe_auto_update')
            ->update(['probe_auto_update' => 0]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_machine')) {
            return;
        }

        Schema::table('v2_machine', function (Blueprint $table) {
            if (Schema::hasColumn('v2_machine', 'probe_auto_update')) {
                $table->dropColumn('probe_auto_update');
            }
        });
    }
};
