<?php

namespace App\Models;

use App\Enums\SupportLogSource;
use App\Enums\SupportOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SupportOrder extends Model
{
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'paw_count' => 'integer',
            'amount' => 'integer',
            'anonymous' => 'boolean',
            'status' => SupportOrderStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentLog(): HasOne
    {
        return $this->hasOne(SupportLog::class)->where('source', SupportLogSource::Payment);
    }
}
