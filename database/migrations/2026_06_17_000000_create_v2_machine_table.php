<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateV2MachineTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('v2_machine')) {
            return;
        }

        Schema::create('v2_machine', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('机器名称');
            $table->string('host')->nullable()->comment('机器IP或域名');
            $table->string('api_token')->unique()->comment('探针通信密钥');
            $table->text('status')->nullable()->comment('资源状态 JSON: CPU, RAM, Network');
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('v2_machine');
    }
}
