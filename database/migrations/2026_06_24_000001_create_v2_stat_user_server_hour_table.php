<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateV2StatUserServerHourTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('v2_stat_user_server_hour')) {
            return;
        }

        Schema::create('v2_stat_user_server_hour', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->integer('server_id')->comment('节点id');
            $table->string('server_type', 32)->comment('节点类型');
            $table->decimal('server_rate', 10, 2);
            $table->bigInteger('u');
            $table->bigInteger('d');
            $table->char('record_type', 2)->default('h');
            $table->integer('record_at')->comment('小时起始时间');
            $table->integer('created_at');
            $table->integer('updated_at');

            $table->unique(['user_id', 'server_id', 'server_type', 'server_rate', 'record_at'], 'user_server_type_rate_hour_at');
            $table->index('user_id');
            $table->index(['server_id', 'server_type'], 'server_hour_type_index');
            $table->index('record_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('v2_stat_user_server_hour');
    }
}
