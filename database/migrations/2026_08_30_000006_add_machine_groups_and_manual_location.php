<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_machine_group')) {
            Schema::create('v2_machine_group', function (Blueprint $table) {
                $table->id();
                $table->string('name', 64)->unique();
                $table->unsignedInteger('sort')->default(0)->index();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        if (!Schema::hasTable('v2_machine')) {
            return;
        }

        $addGroupId = !Schema::hasColumn('v2_machine', 'machine_group_id');
        $addCountryCode = !Schema::hasColumn('v2_machine', 'country_code');
        $addCountryName = !Schema::hasColumn('v2_machine', 'country_name');
        if (!$addGroupId && !$addCountryCode && !$addCountryName) {
            return;
        }

        Schema::table('v2_machine', function (Blueprint $table) use ($addGroupId, $addCountryCode, $addCountryName) {
            if ($addGroupId) {
                $table->unsignedBigInteger('machine_group_id')->nullable()->after('id')->index();
            }
            if ($addCountryCode) {
                $table->char('country_code', 2)->nullable()->after('host');
            }
            if ($addCountryName) {
                $table->string('country_name', 64)->nullable()->after('country_code');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('v2_machine')) {
            if (Schema::hasColumn('v2_machine', 'machine_group_id')) {
                Schema::table('v2_machine', function (Blueprint $table) {
                    $table->dropIndex(['machine_group_id']);
                    $table->dropColumn('machine_group_id');
                });
            }

            $locationColumns = array_values(array_filter([
                Schema::hasColumn('v2_machine', 'country_name') ? 'country_name' : null,
                Schema::hasColumn('v2_machine', 'country_code') ? 'country_code' : null,
            ]));
            if ($locationColumns) {
                Schema::table('v2_machine', function (Blueprint $table) use ($locationColumns) {
                    $table->dropColumn($locationColumns);
                });
            }
        }

        Schema::dropIfExists('v2_machine_group');
    }
};
