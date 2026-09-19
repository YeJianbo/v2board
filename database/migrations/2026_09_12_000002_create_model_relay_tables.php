<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateModelRelayTables extends Migration
{
    public function up(): void
    {
        Schema::create('v2_model_upstream', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name', 100);
            $t->string('base_url', 500);
            $t->text('api_key');
            $t->text('models');
            $t->boolean('enabled')->default(false);
            $t->timestamps();
        });
        Schema::create('v2_model_key', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('machine_id')->unique();
            $t->unsignedInteger('upstream_id')->index();
            $t->string('key_hash', 64)->unique();
            $t->string('key_prefix', 20);
            $t->text('models');
            $t->unsignedBigInteger('quota');
            $t->string('period', 10);
            $t->unsignedInteger('max_output')->default(8192);
            $t->boolean('enabled')->default(true);
            $t->timestamps();
        });
        Schema::create('v2_model_bucket', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('key_id');
            $t->string('period_start', 20);
            $t->unsignedBigInteger('used')->default(0);
            $t->unsignedBigInteger('reserved')->default(0);
            $t->unsignedBigInteger('requests')->default(0);
            $t->unsignedBigInteger('input_tokens')->default(0);
            $t->unsignedBigInteger('output_tokens')->default(0);
            $t->unsignedBigInteger('uncertain')->default(0);
            $t->unique(['key_id', 'period_start']);
        });
        Schema::create('v2_model_usage', function (Blueprint $t) {
            $t->string('request_id', 64)->primary();
            $t->unsignedInteger('key_id');
            $t->unsignedInteger('machine_id')->index();
            $t->unsignedInteger('upstream_id');
            $t->unsignedInteger('bucket_id');
            $t->string('model', 150);
            $t->unsignedBigInteger('reserved');
            $t->unsignedBigInteger('charged')->default(0);
            $t->unsignedBigInteger('input_tokens')->nullable();
            $t->unsignedBigInteger('output_tokens')->nullable();
            $t->string('status', 24)->default('pending');
            $t->unsignedInteger('http_status')->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->string('error_code', 40)->nullable();
            $t->timestamps();
            $t->index(['key_id', 'status']);
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        foreach (['v2_model_usage', 'v2_model_bucket', 'v2_model_key', 'v2_model_upstream'] as $name) Schema::dropIfExists($name);
    }
}
