<?php

namespace App\Models;

use App\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    protected $fillable = ['escrow_order_id', 'amount', 'status', 'out_refund_no'];

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'amount' => 'integer',
        ];
    }

    public function escrowOrder(): BelongsTo
    {
        return $this->belongsTo(EscrowOrder::class);
    }
}
