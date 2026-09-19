<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_notice')) {
            return;
        }

        if (!Schema::hasColumn('v2_notice', 'popup')) {
            Schema::table('v2_notice', function (Blueprint $table) {
                $table->boolean('popup')->default(false)->after('show');
            });
        }

        if (!Schema::hasColumn('v2_notice', 'sort')) {
            Schema::table('v2_notice', function (Blueprint $table) {
                $table->integer('sort')->nullable()->index()->after('popup');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_notice')) {
            return;
        }

        Schema::table('v2_notice', function (Blueprint $table) {
            foreach (['sort', 'popup'] as $column) {
                if (Schema::hasColumn('v2_notice', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
