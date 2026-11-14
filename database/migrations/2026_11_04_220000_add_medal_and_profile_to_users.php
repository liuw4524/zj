<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('growth_points')->default(0)->after('is_blocked');
            $table->unsignedTinyInteger('medal_level')->default(1)->after('growth_points');
            $table->string('province')->nullable()->after('medal_level');
            $table->string('city')->nullable()->after('province');
            $table->string('district')->nullable()->after('city');
            $table->string('address')->nullable()->after('district');
        });

        Schema::create('growth_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('points');
            $table->unsignedInteger('balance_after');
            $table->unsignedTinyInteger('medal_level_after');
            $table->string('source', 32);
            $table->string('subject_type', 32)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->date('earned_on');
            $table->string('dedupe_key')->nullable()->unique();
            $table->string('note')->nullable();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'source', 'earned_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('growth_logs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'growth_points',
                'medal_level',
                'province',
                'city',
                'district',
                'address',
            ]);
        });
    }
};
