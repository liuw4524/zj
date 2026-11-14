<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach ([
            'home_today_new' => '0',
            'home_in_progress' => '0',
            'home_found' => '0',
        ] as $key => $value) {
            if (DB::table('settings')->where('key', $key)->exists()) {
                continue;
            }
            DB::table('settings')->insert([
                'key' => $key,
                'value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'home_today_new',
            'home_in_progress',
            'home_found',
        ])->delete();
    }
};
