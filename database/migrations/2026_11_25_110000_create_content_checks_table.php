<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');
            $table->string('kind', 10);
            $table->string('path')->nullable();
            $table->string('trace_id', 128)->nullable()->unique();
            $table->string('status', 10);
            $table->unsignedInteger('label')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_checks');
    }
};
