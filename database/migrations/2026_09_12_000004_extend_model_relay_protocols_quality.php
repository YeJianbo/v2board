<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ExtendModelRelayProtocolsQuality extends Migration
{
    public function up(): void
    {
        Schema::table('v2_model_upstream', function (Blueprint $t) {
            $t->text('protocols')->nullable();
            $t->text('protocol_urls')->nullable();
            $t->text('pricing')->nullable();
        });
        Schema::table('v2_model_usage', function (Blueprint $t) {
            $t->string('protocol', 24)->default('openai');
            $t->string('response_id', 200)->nullable()->index();
            $t->text('pricing')->nullable();
            $t->unsignedBigInteger('cache_read_tokens')->nullable();
            $t->unsignedBigInteger('cache_write_tokens')->nullable();
            $t->decimal('cost', 24, 12)->nullable();
            $t->string('cost_status', 24)->default('unpriced');
        });
        Schema::table('v2_model_bucket', function (Blueprint $t) {
            $t->decimal('cost', 24, 12)->default(0);
            $t->unsignedBigInteger('cost_unconfirmed')->default(0);
        });
        \Illuminate\Support\Facades\DB::table('v2_model_bucket')->update(['cost_unconfirmed' => \Illuminate\Support\Facades\DB::raw('requests')]);
        Schema::create('v2_model_quality_config', function (Blueprint $t) {
            $t->unsignedInteger('upstream_id')->primary();
            $t->text('settings');
            $t->timestamp('next_run_at')->nullable()->index();
            $t->string('state', 24)->default('untested');
            $t->unsignedInteger('bad_streak')->default(0);
            $t->unsignedInteger('baseline_run_id')->nullable();
            $t->unsignedInteger('baseline_score')->nullable();
            $t->unsignedInteger('last_score')->nullable();
            $t->timestamps();
        });
        Schema::create('v2_model_quality_run', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('upstream_id')->index();
            $t->string('model', 150);
            $t->string('protocol', 24);
            $t->string('source', 12);
            $t->string('status', 24)->default('queued')->index();
            $t->text('settings');
            $t->longText('results')->nullable();
            $t->unsignedInteger('score')->nullable();
            $t->unsignedBigInteger('tokens')->nullable();
            $t->text('pricing')->nullable();
            $t->decimal('cost', 24, 12)->nullable();
            $t->string('cost_status', 24)->default('unpriced');
            $t->boolean('alert')->default(false);
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('v2_model_quality_run');
        Schema::dropIfExists('v2_model_quality_config');
        Schema::table('v2_model_usage', fn (Blueprint $t) => $t->dropColumn(['protocol', 'response_id', 'pricing', 'cache_read_tokens', 'cache_write_tokens', 'cost', 'cost_status']));
        Schema::table('v2_model_bucket', fn (Blueprint $t) => $t->dropColumn(['cost', 'cost_unconfirmed']));
        Schema::table('v2_model_upstream', fn (Blueprint $t) => $t->dropColumn(['protocols', 'protocol_urls', 'pricing']));
    }
}
