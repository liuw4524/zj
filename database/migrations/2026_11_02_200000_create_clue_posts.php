<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clue_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('description');
            $table->string('province', 40);
            $table->string('city', 40);
            $table->timestamp('found_at');
            $table->string('contact_name', 40);
            $table->string('contact_phone', 20);
            $table->boolean('contact_public')->default(false);
            $table->unsignedTinyInteger('status');
            $table->timestamp('lit_until')->nullable();
            $table->timestamp('delisted_at')->nullable();
            $table->string('reject_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'lit_until']);
            $table->index(['city', 'status']);
            $table->index('delisted_at');
        });

        Schema::create('clue_post_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clue_post_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedTinyInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('clue_contact_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clue_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('status');
            $table->timestamps();

            $table->index(['clue_post_id', 'user_id', 'status']);
        });

        $now = now();
        if (! DB::table('settings')->where('key', 'max_active_clue_posts')->exists()) {
            DB::table('settings')->insert([
                'key' => 'max_active_clue_posts',
                'value' => '3',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clue_contact_requests');
        Schema::dropIfExists('clue_post_images');
        Schema::dropIfExists('clue_posts');
        DB::table('settings')->where('key', 'max_active_clue_posts')->delete();
    }
};
