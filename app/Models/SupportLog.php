<?php

namespace App\Models;

use App\Enums\SupportLogSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportLog extends Model
{
    protected $fillable = [
        'user_id',
        'paws',
        'balance_after',
        'source',
        'support_order_id',
        'note',
        'operator_id',
    ];

    protected function casts(): array
    {
        return [
            'paws' => 'integer',
            'balance_after' => 'integer',
            'source' => SupportLogSource::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
