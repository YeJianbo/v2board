<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRavelControlPlane extends Migration
{
    public function up()
    {
        if (Schema::hasTable('v2_server_v2node')) {
            Schema::table('v2_server_v2node', function (Blueprint $table) {
                if (!Schema::hasColumn('v2_server_v2node', 'ravel_authority')) {
                    $table->string('ravel_authority')->nullable()->comment('Ravel HTTP authority');
                }
                if (!Schema::hasColumn('v2_server_v2node', 'ravel_path')) {
                    $table->string('ravel_path', 2048)->nullable()->comment('Ravel request path');
                }
                if (!Schema::hasColumn('v2_server_v2node', 'ravel_gateway_group')) {
                    $table->char('ravel_gateway_group', 8)->nullable()->comment('Ravel 8-byte gateway group');
                }
                if (!Schema::hasColumn('v2_server_v2node', 'ravel_masquerade')) {
                    $table->string('ravel_masquerade', 2048)->nullable()->comment('Ravel masquerade URL');
                }
                if (!Schema::hasColumn('v2_server_v2node', 'ravel_settings')) {
                    $table->text('ravel_settings')->nullable()->comment('Non-secret Ravel settings');
                }
            });
        }

        if (!Schema::hasTable('v2_ravel_credential')) {
            Schema::create('v2_ravel_credential', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('server_id');
                $table->unsignedInteger('user_id');
                $table->char('credential_id', 32);
                $table->text('capability_key_ciphertext');
                $table->unsignedInteger('key_version');
                $table->unsignedBigInteger('policy_id')->default(0);
                $table->char('gateway_group', 8);
                $table->unsignedBigInteger('not_before');
                $table->unsignedBigInteger('not_after');
                $table->unsignedBigInteger('revoked_at')->nullable();
                $table->unsignedBigInteger('superseded_at')->nullable();
                $table->unsignedBigInteger('created_at');
                $table->unsignedBigInteger('updated_at');

                $table->unique('credential_id', 'uq_ravel_credential_id');
                $table->unique(
                    ['server_id', 'user_id', 'key_version'],
                    'uq_ravel_server_user_version'
                );
                $table->index(
                    ['server_id', 'revoked_at', 'not_before', 'not_after'],
                    'idx_ravel_server_active'
                );
                $table->index(
                    ['user_id', 'server_id', 'revoked_at', 'not_after'],
                    'idx_ravel_user_server_active'
                );
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('v2_ravel_credential');

        if (!Schema::hasTable('v2_server_v2node')) {
            return;
        }

        $columns = [
            'ravel_authority',
            'ravel_path',
            'ravel_gateway_group',
            'ravel_masquerade',
            'ravel_settings',
        ];

        Schema::table('v2_server_v2node', function (Blueprint $table) use ($columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn('v2_server_v2node', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}
