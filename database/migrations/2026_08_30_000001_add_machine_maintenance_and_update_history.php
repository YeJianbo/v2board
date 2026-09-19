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
                if (!Schema::hasColumn('v2_machine', 'maintenance_until')) {
                    $table->integer('maintenance_until')->default(0)->after('probe_auto_update');
                }
                if (!Schema::hasColumn('v2_machine', 'maintenance_note')) {
                    $table->string('maintenance_note', 191)->nullable()->after('maintenance_until');
                }
            });
        }

        if (!Schema::hasTable('v2_machine_update_log')) {
            Schema::create('v2_machine_update_log', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('machine_id');
                $table->string('request_id', 64);
                $table->string('source', 16)->default('manual');
                $table->string('target_version', 64)->default('latest');
                $table->string('installed_version', 64)->nullable();
                $table->string('status', 16)->default('pending');
                $table->string('error', 255)->nullable();
                $table->integer('requested_at');
                $table->integer('started_at')->default(0);
                $table->integer('completed_at')->default(0);
                $table->integer('expires_at')->default(0);
                $table->integer('created_at');
                $table->integer('updated_at');

                $table->index(['machine_id', 'requested_at'], 'machine_update_machine_requested_idx');
                $table->index(['machine_id', 'request_id'], 'machine_update_machine_request_idx');
                $table->index(['status', 'expires_at'], 'machine_update_status_expires_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_machine_update_log');

        if (!Schema::hasTable('v2_machine')) {
            return;
        }

        Schema::table('v2_machine', function (Blueprint $table) {
            if (Schema::hasColumn('v2_machine', 'maintenance_note')) {
                $table->dropColumn('maintenance_note');
            }
            if (Schema::hasColumn('v2_machine', 'maintenance_until')) {
                $table->dropColumn('maintenance_until');
            }
        });
    }
};
