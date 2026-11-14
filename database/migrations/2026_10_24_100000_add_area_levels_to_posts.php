<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('province', 40)->nullable()->after('city');
            $table->string('district', 40)->nullable()->after('province');
            $table->string('town', 40)->nullable()->after('district');
            $table->index(['province', 'city']);
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['province', 'city']);
            $table->dropColumn(['province', 'district', 'town']);
        });
    }
};
