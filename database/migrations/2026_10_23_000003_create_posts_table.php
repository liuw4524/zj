<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pet_name', 40);
            $table->string('breed', 40)->nullable();
            $table->text('description');
            $table->string('city', 40);
            $table->timestamp('lost_at');
            $table->unsignedInteger('reward_amount');
            $table->string('contact_name', 40);
            $table->string('contact_phone', 20);
            $table->unsignedTinyInteger('status');
            $table->timestamp('lit_until')->nullable();
            $table->string('active_key')->nullable()->unique();
            $table->string('reject_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'lit_until']);
            $table->index('city');
            $table->index('lost_at');
        });

        Schema::create('post_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedTinyInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_images');
        Schema::dropIfExists('posts');
    }
};
