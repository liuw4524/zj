<?php

namespace App\Models;

use App\Enums\EscrowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EscrowOrder extends Model
{
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'status' => EscrowStatus::class,
            'amount' => 'integer',
            'commission_rate' => 'integer',
            'commission_amount' => 'integer',
            'payee_amount' => 'integer',
            'paid_at' => 'datetime',
            'payout_ready_at' => 'datetime',
            'cooling_started_at' => 'datetime',
            'settle_due_at' => 'datetime',
            'settled_at' => 'datetime',
            'ops_followed_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
