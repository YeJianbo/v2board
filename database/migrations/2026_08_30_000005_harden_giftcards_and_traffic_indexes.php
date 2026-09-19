<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HardenGiftcardsAndTrafficIndexes extends Migration
{
    public function up()
    {
        if (Schema::hasTable('v2_giftcard')) {
            Schema::table('v2_giftcard', function (Blueprint $table) {
                if (!Schema::hasColumn('v2_giftcard', 'template_id')) {
                    $table->unsignedInteger('template_id')->nullable()->after('id');
                }
                if (!Schema::hasColumn('v2_giftcard', 'enabled')) {
                    $table->boolean('enabled')->default(true)->after('limit_use');
                }
                if (!Schema::hasColumn('v2_giftcard', 'used_at')) {
                    $table->integer('used_at')->nullable()->after('used_user_ids');
                }
                if (!Schema::hasColumn('v2_giftcard', 'used_by_user_id')) {
                    $table->unsignedInteger('used_by_user_id')->nullable()->after('used_at');
                }
            });

            $this->addIndexIfMissing('v2_giftcard', 'giftcard_template_id', ['template_id']);
            $this->addIndexIfMissing('v2_giftcard', 'giftcard_code_unique', ['code'], true);
        }

        if (!Schema::hasTable('v2_giftcard_usage')) {
            Schema::create('v2_giftcard_usage', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('giftcard_id');
                $table->unsignedInteger('user_id');
                $table->tinyInteger('type');
                $table->integer('value')->nullable();
                $table->unsignedInteger('plan_id')->nullable();
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');

                $table->unique(['giftcard_id', 'user_id'], 'giftcard_usage_card_user');
                $table->index(['user_id', 'created_at'], 'giftcard_usage_user_time');
                $table->index('created_at', 'giftcard_usage_time');
            });
        }

        if (!Schema::hasTable('v2_processing_batch')) {
            Schema::create('v2_processing_batch', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->char('batch_id', 32)->unique();
                $table->string('stream', 96);
                $table->integer('created_at');
                $table->index('created_at', 'processing_batch_created_at');
            });
        }

        foreach (['v2_stat_user_server', 'v2_stat_user_server_hour', 'v2_stat_user_server_minute'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $prefix = str_replace('v2_stat_user_server', 'sus', $table);
            $this->addIndexIfMissing($table, $prefix . '_user_record_at', ['user_id', 'record_at']);
            $this->addIndexIfMissing($table, $prefix . '_server_record_at', ['server_id', 'server_type', 'record_at']);
        }
    }

    public function down()
    {
        Schema::dropIfExists('v2_processing_batch');
        Schema::dropIfExists('v2_giftcard_usage');

        foreach (['v2_stat_user_server', 'v2_stat_user_server_hour', 'v2_stat_user_server_minute'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $prefix = str_replace('v2_stat_user_server', 'sus', $table);
            $this->dropIndexIfPresent($table, $prefix . '_user_record_at');
            $this->dropIndexIfPresent($table, $prefix . '_server_record_at');
        }

        if (Schema::hasTable('v2_giftcard')) {
            $this->dropIndexIfPresent('v2_giftcard', 'giftcard_template_id');
            $this->dropIndexIfPresent('v2_giftcard', 'giftcard_code_unique');
            Schema::table('v2_giftcard', function (Blueprint $table) {
                $columns = array_values(array_filter(
                    ['template_id', 'enabled', 'used_at', 'used_by_user_id'],
                    static fn ($column) => Schema::hasColumn('v2_giftcard', $column)
                ));
                if ($columns) {
                    $table->dropColumn($columns);
                }
            });
        }
    }

    private function addIndexIfMissing(string $table, string $name, array $columns, bool $unique = false): void
    {
        if ($this->hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name, $columns, $unique) {
            $unique ? $blueprint->unique($columns, $name) : $blueprint->index($columns, $name);
        });
    }

    private function dropIndexIfPresent(string $table, string $name): void
    {
        if (!$this->hasIndex($table, $name)) {
            return;
        }
        Schema::table($table, static function (Blueprint $blueprint) use ($name) {
            $blueprint->dropIndex($name);
        });
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
