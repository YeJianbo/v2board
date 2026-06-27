<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateV2StatUserServerMinuteTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('v2_stat_user_server_minute')) {
            return;
        }

        Schema::create('v2_stat_user_server_minute', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->integer('server_id')->comment('节点id');
            $table->string('server_type', 32)->comment('节点类型');
            $table->decimal('server_rate', 10, 2);
            $table->bigInteger('u');
            $table->bigInteger('d');
            $table->char('record_type', 2)->default('m');
            $table->integer('record_at')->comment('分钟起始时间');
            $table->integer('created_at');
            $table->integer('updated_at');

            $table->unique(['user_id', 'server_id', 'server_type', 'server_rate', 'record_at'], 'user_server_type_rate_minute_at');
            $table->index('user_id');
            $table->index(['server_id', 'server_type'], 'server_minute_type_index');
            $table->index('record_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('v2_stat_user_server_minute');
    }
}
