<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->unsignedInteger('view_count')->default(0)->after('reject_reason');
            $table->unsignedInteger('share_count')->default(0)->after('view_count');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 40);
            $table->string('title');
            $table->string('body');
            $table->string('dedupe_key')->unique();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['view_count', 'share_count']);
        });
    }
};
