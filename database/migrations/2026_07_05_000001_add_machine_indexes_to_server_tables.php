<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddMachineIndexesToServerTables extends Migration
{
    private array $indexes = [
        ['v2_server_vmess', 'machine_id', 'idx_vmess_machine_id'],
        ['v2_server_trojan', 'machine_id', 'idx_trojan_machine_id'],
        ['v2_server_shadowsocks', 'machine_id', 'idx_ss_machine_id'],
        ['v2_server_vless', 'machine_id', 'idx_vless_machine_id'],
        ['v2_server_hysteria', 'machine_id', 'idx_hysteria_machine_id'],
        ['v2_server_tuic', 'machine_id', 'idx_tuic_machine_id'],
        ['v2_server_v2node', 'machine_id', 'idx_v2node_machine_id'],
        ['v2_server_v2node', 'relay_machine_id', 'idx_v2node_relay_machine_id'],
        ['v2_server_v2node', 'parent_id', 'idx_v2node_parent_id'],
    ];

    public function up()
    {
        foreach ($this->indexes as [$tableName, $columnName, $indexName]) {
            if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, $columnName) || $this->indexExists($tableName, $indexName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($columnName, $indexName) {
                $table->index($columnName, $indexName);
            });
        }
    }

    public function down()
    {
        foreach (array_reverse($this->indexes) as [$tableName, $columnName, $indexName]) {
            if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, $columnName) || !$this->indexExists($tableName, $indexName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                $table->dropIndex($indexName);
            });
        }
    }

    private function indexExists(string $tableName, string $indexName): bool
    {
        $rows = DB::select(
            "SHOW INDEX FROM `{$tableName}` WHERE Key_name = ?",
            [$indexName]
        );

        return !empty($rows);
    }
}
