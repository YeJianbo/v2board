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
            if (!Schema::hasColumn('v2_machine', 'sort')) {
                $table->unsignedInteger('sort')->default(0)->after('status');
            }
            if (!Schema::hasColumn('v2_machine', 'public_visible')) {
                $table->tinyInteger('public_visible')->default(1)->after('sort');
            }
            if (!Schema::hasColumn('v2_machine', 'traffic_alert_enabled')) {
                $table->tinyInteger('traffic_alert_enabled')->default(0)->after('public_visible');
            }
            if (!Schema::hasColumn('v2_machine', 'traffic_alert_bytes')) {
                $table->unsignedBigInteger('traffic_alert_bytes')->default(0)->after('traffic_alert_enabled');
            }
            if (!Schema::hasColumn('v2_machine', 'traffic_alert_window')) {
                $table->unsignedInteger('traffic_alert_window')->default(86400)->after('traffic_alert_bytes');
            }
        });

        DB::table('v2_machine')
            ->orderBy('id')
            ->get(['id'])
            ->each(function ($machine, $index) {
                DB::table('v2_machine')
                    ->where('id', $machine->id)
                    ->where('sort', 0)
                    ->update(['sort' => $index + 1]);
            });
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_machine')) {
            return;
        }

        Schema::table('v2_machine', function (Blueprint $table) {
            foreach ([
                'traffic_alert_window',
                'traffic_alert_bytes',
                'traffic_alert_enabled',
                'public_visible',
                'sort',
            ] as $column) {
                if (Schema::hasColumn('v2_machine', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
