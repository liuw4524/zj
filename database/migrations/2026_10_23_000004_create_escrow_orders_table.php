<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escrow_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->unsignedTinyInteger('status');
            $table->string('out_trade_no')->unique();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedTinyInteger('commission_rate')->nullable();
            $table->unsignedInteger('commission_amount')->nullable();
            $table->unsignedInteger('payee_amount')->nullable();
            $table->timestamp('cooling_started_at')->nullable();
            $table->timestamp('settle_due_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->unsignedBigInteger('lead_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escrow_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->unsignedTinyInteger('status');
            $table->string('out_refund_no')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('escrow_orders');
    }
};
