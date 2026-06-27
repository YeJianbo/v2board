<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRelayMachineIdToV2nodeServersTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('v2_server_v2node')) {
            return;
        }

        Schema::table('v2_server_v2node', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_server_v2node', 'relay_machine_id')) {
                $table->integer('relay_machine_id')->nullable()->comment('中转机ID');
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('v2_server_v2node')) {
            return;
        }

        Schema::table('v2_server_v2node', function (Blueprint $table) {
            if (Schema::hasColumn('v2_server_v2node', 'relay_machine_id')) {
                $table->dropColumn('relay_machine_id');
            }
        });
    }
}
