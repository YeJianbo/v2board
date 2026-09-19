<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddAdminDashboardIndexes extends Migration
{
    private const INDEXES = [
        ['v2_order', 'order_dashboard_created_status_idx', ['created_at', 'status']],
        ['v2_order', 'order_dashboard_commission_idx', ['commission_status', 'status', 'commission_balance', 'invite_user_id']],
        ['v2_user', 'user_dashboard_online_idx', ['t']],
        ['v2_user', 'user_dashboard_created_idx', ['created_at']],
        ['v2_user', 'user_dashboard_expired_idx', ['expired_at']],
        ['v2_commission_log', 'commission_dashboard_created_idx', ['created_at']],
        ['v2_ticket', 'ticket_dashboard_status_idx', ['status']],
    ];

    public function up()
    {
        foreach (self::INDEXES as [$table, $name, $columns]) {
            if (!$this->canCreateIndex($table, $name, $columns)) {
                continue;
            }

            Schema::table($table, static function (Blueprint $blueprint) use ($name, $columns) {
                $blueprint->index($columns, $name);
            });
        }
    }

    public function down()
    {
        foreach (array_reverse(self::INDEXES) as [$table, $name]) {
            if (!Schema::hasTable($table) || !$this->hasIndex($table, $name)) {
                continue;
            }

            Schema::table($table, static function (Blueprint $blueprint) use ($name) {
                $blueprint->dropIndex($name);
            });
        }
    }

    private function canCreateIndex(string $table, string $name, array $columns): bool
    {
        if (!Schema::hasTable($table) || $this->hasIndex($table, $name)) {
            return false;
        }

        foreach ($columns as $column) {
            if (!Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    private function hasIndex(string $table, string $name): bool
    {
        $database = DB::connection()->getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $name)
            ->exists();
    }
}
