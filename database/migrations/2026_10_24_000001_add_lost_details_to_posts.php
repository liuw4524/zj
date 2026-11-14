<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('lost_place', 100)->nullable()->after('city');
            $table->text('lost_story')->nullable()->after('lost_place');
            $table->text('pet_traits')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['lost_place', 'lost_story', 'pet_traits']);
        });
    }
};
