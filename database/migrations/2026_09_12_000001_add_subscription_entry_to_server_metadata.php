<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSubscriptionEntryToServerMetadata extends Migration
{
    public function up(): void
    {
        Schema::table('v2_server_metadata', function (Blueprint $table) {
            $table->unsignedInteger('entry_machine_id')->nullable()->index();
            $table->string('entry_host', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('v2_server_metadata', function (Blueprint $table) {
            $table->dropIndex(['entry_machine_id']);
            $table->dropColumn(['entry_machine_id', 'entry_host']);
        });
    }
}
