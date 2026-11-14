<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->string('contact_name', 40);
            $table->string('contact_phone', 20);
            $table->unsignedTinyInteger('status');
            $table->timestamp('matched_at')->nullable();
            $table->timestamp('contact_visible_until')->nullable();
            $table->timestamps();

            $table->index(['post_id', 'status']);
        });

        Schema::create('lead_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedTinyInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::table('escrow_orders', function (Blueprint $table) {
            $table->foreign('lead_id')->references('id')->on('leads')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('escrow_orders', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
        });
        Schema::dropIfExists('lead_images');
        Schema::dropIfExists('leads');
    }
};
