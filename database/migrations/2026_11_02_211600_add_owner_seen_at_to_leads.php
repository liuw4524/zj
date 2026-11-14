<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('owner_seen_at')->nullable()->after('contact_visible_until');
        });

        DB::table('leads')->whereNull('owner_seen_at')->update(['owner_seen_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('owner_seen_at');
        });
    }
};
