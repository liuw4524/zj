<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('settings')->where('key', 'contact_platform_image')->exists()) {
            return;
        }

        $now = now();
        DB::table('settings')->insert([
            'key' => 'contact_platform_image',
            'value' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'contact_platform_image')->delete();
    }
};
