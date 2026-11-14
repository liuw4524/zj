<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('settings')->where('key', 'rescue_team_image')->exists()) {
            return;
        }

        $now = now();
        DB::table('settings')->insert([
            'key' => 'rescue_team_image',
            'value' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'rescue_team_image')->delete();
    }
};
