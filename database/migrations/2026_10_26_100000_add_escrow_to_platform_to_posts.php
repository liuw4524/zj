<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->boolean('escrow_to_platform')->default(false)->after('reward_amount');
        });

        $postIds = DB::table('escrow_orders')->pluck('post_id');
        if ($postIds->isNotEmpty()) {
            DB::table('posts')->whereIn('id', $postIds)->update(['escrow_to_platform' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('escrow_to_platform');
        });
    }
};
