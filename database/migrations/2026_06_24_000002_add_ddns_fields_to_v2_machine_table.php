<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDdnsFieldsToV2MachineTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('v2_machine')) {
            return;
        }

        Schema::table('v2_machine', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_machine', 'ddns_enabled')) {
                $table->tinyInteger('ddns_enabled')->default(0)->after('api_token');
            }
            if (!Schema::hasColumn('v2_machine', 'ddns_provider')) {
                $table->string('ddns_provider', 32)->nullable()->after('ddns_enabled');
            }
            if (!Schema::hasColumn('v2_machine', 'ddns_zone_name')) {
                $table->string('ddns_zone_name', 191)->nullable()->after('ddns_provider');
            }
            if (!Schema::hasColumn('v2_machine', 'ddns_record_name')) {
                $table->string('ddns_record_name', 191)->nullable()->after('ddns_zone_name');
            }
            if (!Schema::hasColumn('v2_machine', 'ddns_record_type')) {
                $table->string('ddns_record_type', 8)->nullable()->after('ddns_record_name');
            }
            if (!Schema::hasColumn('v2_machine', 'ddns_ttl')) {
                $table->integer('ddns_ttl')->nullable()->after('ddns_record_type');
            }
            if (!Schema::hasColumn('v2_machine', 'ddns_proxied')) {
                $table->tinyInteger('ddns_proxied')->default(0)->after('ddns_ttl');
            }
            if (!Schema::hasColumn('v2_machine', 'ddns_api_token')) {
                $table->text('ddns_api_token')->nullable()->after('ddns_proxied');
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('v2_machine')) {
            return;
        }

        Schema::table('v2_machine', function (Blueprint $table) {
            foreach ([
                'ddns_api_token',
                'ddns_proxied',
                'ddns_ttl',
                'ddns_record_type',
                'ddns_record_name',
                'ddns_zone_name',
                'ddns_provider',
                'ddns_enabled',
            ] as $column) {
                if (Schema::hasColumn('v2_machine', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}
