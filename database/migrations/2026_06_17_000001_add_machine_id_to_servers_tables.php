<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMachineIdToServersTables extends Migration
{
    public function up()
    {
        $tables = [
            'v2_server_vmess',
            'v2_server_trojan',
            'v2_server_shadowsocks',
            'v2_server_vless',
            'v2_server_hysteria',
            'v2_server_tuic'
        ];

        foreach ($tables as $t) {
            if (Schema::hasTable($t)) {
                Schema::table($t, function (Blueprint $table) use ($t) {
                    if (!Schema::hasColumn($t, 'machine_id')) {
                        $table->integer('machine_id')->nullable()->comment('物理机ID');
                    }
                });
            }
        }
    }

    public function down()
    {
        $tables = [
            'v2_server_vmess',
            'v2_server_trojan',
            'v2_server_shadowsocks',
            'v2_server_vless',
            'v2_server_hysteria',
            'v2_server_tuic'
        ];

        foreach ($tables as $t) {
            if (Schema::hasTable($t)) {
                Schema::table($t, function (Blueprint $table) use ($t) {
                    if (Schema::hasColumn($t, 'machine_id')) {
                        $table->dropColumn('machine_id');
                    }
                });
            }
        }
    }
}
