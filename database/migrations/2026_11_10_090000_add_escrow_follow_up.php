<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('escrow_orders', function (Blueprint $table) {
            $table->timestamp('payout_ready_at')->nullable()->after('paid_at');
            $table->string('ops_note')->nullable()->after('settled_at');
            $table->timestamp('ops_followed_at')->nullable()->after('ops_note');
            $table->string('payout_note')->nullable()->after('ops_followed_at');
        });

        DB::table('escrow_orders')
            ->where('status', 5)
            ->whereNull('payout_ready_at')
            ->update(['payout_ready_at' => DB::raw('updated_at')]);

        Schema::create('ops_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_notifications');

        Schema::table('escrow_orders', function (Blueprint $table) {
            $table->dropColumn(['payout_ready_at', 'ops_note', 'ops_followed_at', 'payout_note']);
        });
    }
};
