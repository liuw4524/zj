<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('settings')->where('key', 'post_review_enabled')->exists()) {
            return;
        }

        $now = now();
        DB::table('settings')->insert([
            'key' => 'post_review_enabled',
            'value' => '1',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'post_review_enabled')->delete();
    }
};
