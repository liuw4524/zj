<?php

namespace App\Models;

use App\Enums\GrowthSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrowthLog extends Model
{
    protected $fillable = [
        'user_id',
        'points',
        'balance_after',
        'medal_level_after',
        'source',
        'subject_type',
        'subject_id',
        'earned_on',
        'dedupe_key',
        'note',
        'operator_id',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'balance_after' => 'integer',
            'medal_level_after' => 'integer',
            'source' => GrowthSource::class,
            'earned_on' => 'date',
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
