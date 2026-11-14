<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->boolean('forwarded_wechat')->default(false)->after('share_count');
            $table->boolean('forwarded_douyin')->default(false)->after('forwarded_wechat');
            $table->boolean('forwarded_rescue_team')->default(false)->after('forwarded_douyin');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn([
                'forwarded_wechat',
                'forwarded_douyin',
                'forwarded_rescue_team',
            ]);
        });
    }
};
