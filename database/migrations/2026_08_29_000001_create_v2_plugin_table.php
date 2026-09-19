<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v2_plugin')) {
            return;
        }

        Schema::create('v2_plugin', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('version', 32);
            $table->string('type', 32)->default('feature');
            $table->boolean('is_enabled')->default(false);
            $table->json('config')->nullable();
            $table->unsignedBigInteger('installed_at')->nullable();
            $table->unsignedBigInteger('created_at')->nullable();
            $table->unsignedBigInteger('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_plugin');
    }
};
