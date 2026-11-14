<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wechat_payments', function (Blueprint $table) {
            $table->id();
            $table->string('out_trade_no', 32)->unique();
            $table->unsignedInteger('amount');
            $table->string('kind', 20);
            $table->foreignId('escrow_order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('support_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('prepay_id')->nullable();
            $table->string('transaction_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['escrow_order_id', 'kind', 'paid_at']);
            $table->index(['support_order_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wechat_payments');
    }
};
