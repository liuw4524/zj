<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('settings')->where('key', 'max_daily_clue_posts')->exists()) {
            return;
        }

        $now = now();
        DB::table('settings')->insert([
            'key' => 'max_daily_clue_posts',
            'value' => '3',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'max_daily_clue_posts')->delete();
    }
};
