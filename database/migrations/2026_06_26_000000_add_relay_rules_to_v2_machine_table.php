<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_machine', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_machine', 'relay_rules')) {
                $table->json('relay_rules')->nullable()->after('ddns_api_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('v2_machine', function (Blueprint $table) {
            if (Schema::hasColumn('v2_machine', 'relay_rules')) {
                $table->dropColumn('relay_rules');
            }
        });
    }
};
