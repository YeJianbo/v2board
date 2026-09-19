<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v2_machine_metric_hour')) {
            return;
        }

        Schema::create('v2_machine_metric_hour', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('machine_id');
            $table->decimal('cpu', 5, 2)->nullable();
            $table->decimal('memory', 5, 2)->nullable();
            $table->decimal('disk', 5, 2)->nullable();
            $table->decimal('swap', 5, 2)->nullable();
            $table->decimal('load_1', 10, 3)->nullable();
            $table->decimal('load_5', 10, 3)->nullable();
            $table->decimal('load_15', 10, 3)->nullable();
            $table->unsignedBigInteger('net_in_rate')->default(0);
            $table->unsignedBigInteger('net_out_rate')->default(0);
            $table->unsignedBigInteger('mem_total')->default(0);
            $table->unsignedBigInteger('mem_used')->default(0);
            $table->unsignedBigInteger('disk_total')->default(0);
            $table->unsignedBigInteger('disk_used')->default(0);
            $table->unsignedBigInteger('swap_total')->default(0);
            $table->unsignedBigInteger('swap_used')->default(0);
            $table->unsignedInteger('sample_count')->default(0);
            $table->unsignedInteger('recorded_at');
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');

            $table->unique(['machine_id', 'recorded_at'], 'machine_metric_machine_hour_unique');
            $table->index('recorded_at', 'machine_metric_hour_recorded_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_machine_metric_hour');
    }
};
