<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAgentOperations extends Migration
{
    public function up(): void
    {
        Schema::create('v2_agent_operation', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('admin_id');
            $table->string('token_hash', 64)->unique();
            $table->string('tool', 40);
            $table->unsignedInteger('node_count');
            $table->longText('snapshot');
            $table->string('status', 16)->default('applied');
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('undone_at')->nullable();
            $table->unsignedInteger('undone_by')->nullable();
            $table->index(['admin_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_agent_operation');
    }
}
