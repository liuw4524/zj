<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WechatPayment extends Model
{
    public const ESCROW = 'escrow';

    public const TOPUP = 'topup';

    public const SUPPORT = 'support';

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function escrowOrder(): BelongsTo
    {
        return $this->belongsTo(EscrowOrder::class);
    }

    public function supportOrder(): BelongsTo
    {
        return $this->belongsTo(SupportOrder::class);
    }
}
