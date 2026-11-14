<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('support_paws')->default(0)->after('address');
        });

        Schema::create('support_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('paw_count');
            $table->unsignedInteger('amount');
            $table->boolean('anonymous')->default(false);
            $table->unsignedTinyInteger('status');
            $table->string('out_trade_no')->unique();
            $table->string('client_token', 64);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'client_token']);
            $table->index(['status', 'id']);
        });

        Schema::create('support_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('paws');
            $table->unsignedInteger('balance_after');
            $table->string('source', 32);
            $table->foreignId('support_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        if (! DB::table('settings')->where('key', 'support_wall_enabled')->exists()) {
            $now = now();
            DB::table('settings')->insert([
                'key' => 'support_wall_enabled',
                'value' => '1',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'support_wall_enabled')->delete();
        Schema::dropIfExists('support_logs');
        Schema::dropIfExists('support_orders');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('support_paws');
        });
    }
};
