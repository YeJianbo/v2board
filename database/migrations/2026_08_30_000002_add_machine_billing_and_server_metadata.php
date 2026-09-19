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
                if (!Schema::hasColumn('v2_machine', 'traffic_reset_mode')) {
                    $table->string('traffic_reset_mode', 16)->default('rolling')->after('traffic_alert_window');
                }
                if (!Schema::hasColumn('v2_machine', 'traffic_reset_day')) {
                    $table->unsignedTinyInteger('traffic_reset_day')->default(1)->after('traffic_reset_mode');
                }
                if (!Schema::hasColumn('v2_machine', 'traffic_reset_hour')) {
                    $table->unsignedTinyInteger('traffic_reset_hour')->default(0)->after('traffic_reset_day');
                }
                if (!Schema::hasColumn('v2_machine', 'traffic_reset_minute')) {
                    $table->unsignedTinyInteger('traffic_reset_minute')->default(0)->after('traffic_reset_hour');
                }
                if (!Schema::hasColumn('v2_machine', 'idc_name')) {
                    $table->string('idc_name', 100)->nullable()->after('traffic_reset_minute');
                }
                if (!Schema::hasColumn('v2_machine', 'idc_url')) {
                    $table->string('idc_url', 255)->nullable()->after('idc_name');
                }
                if (!Schema::hasColumn('v2_machine', 'billing_amount')) {
                    $table->decimal('billing_amount', 12, 2)->default(0)->after('idc_url');
                }
                if (!Schema::hasColumn('v2_machine', 'billing_currency')) {
                    $table->string('billing_currency', 8)->default('USD')->after('billing_amount');
                }
                if (!Schema::hasColumn('v2_machine', 'billing_cycle')) {
                    $table->string('billing_cycle', 16)->default('monthly')->after('billing_currency');
                }
                if (!Schema::hasColumn('v2_machine', 'renew_at')) {
                    $table->unsignedInteger('renew_at')->default(0)->after('billing_cycle');
                }
                if (!Schema::hasColumn('v2_machine', 'renew_alert_enabled')) {
                    $table->tinyInteger('renew_alert_enabled')->default(0)->after('renew_at');
                }
                if (!Schema::hasColumn('v2_machine', 'renew_alert_days')) {
                    $table->unsignedSmallInteger('renew_alert_days')->default(7)->after('renew_alert_enabled');
                }
            });
        }

        if (!Schema::hasTable('v2_server_metadata')) {
            Schema::create('v2_server_metadata', function (Blueprint $table) {
                $table->id();
                $table->string('server_type', 32);
                $table->unsignedBigInteger('server_id');
                $table->char('country_code', 2)->nullable();
                $table->string('country_name', 64)->nullable();
                $table->string('display_group', 64)->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');

                $table->unique(['server_type', 'server_id'], 'server_metadata_type_id_unique');
                $table->index(['country_code', 'display_group'], 'server_metadata_country_group_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_server_metadata');

        if (!Schema::hasTable('v2_machine')) {
            return;
        }

        Schema::table('v2_machine', function (Blueprint $table) {
            foreach ([
                'renew_alert_days',
                'renew_alert_enabled',
                'renew_at',
                'billing_cycle',
                'billing_currency',
                'billing_amount',
                'idc_url',
                'idc_name',
                'traffic_reset_minute',
                'traffic_reset_hour',
                'traffic_reset_day',
                'traffic_reset_mode',
            ] as $column) {
                if (Schema::hasColumn('v2_machine', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
