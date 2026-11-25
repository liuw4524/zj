<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentCheck extends Model
{
    public const POST = 'post';

    public const CLUE = 'clue_post';

    public const LEAD = 'lead';

    public const TEXT = 'text';

    public const IMAGE = 'image';

    public const PENDING = 'pending';

    public const PASS = 'pass';

    public const REVIEW = 'review';

    public const RISKY = 'risky';

    protected $fillable = [
        'user_id',
        'subject_type',
        'subject_id',
        'kind',
        'path',
        'trace_id',
        'status',
        'label',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hasStatus(string $type, int $id, array $statuses): bool
    {
        return static::query()
            ->where('subject_type', $type)
            ->where('subject_id', $id)
            ->whereIn('status', $statuses)
            ->exists();
    }

    public static function holds(string $type, int $id): bool
    {
        return static::hasStatus($type, $id, [self::PENDING, self::REVIEW, self::RISKY]);
    }

    public static function hasRisky(string $type, int $id): bool
    {
        return static::hasStatus($type, $id, [self::RISKY]);
    }

    public static function withholds(string $type, int $id): bool
    {
        return static::hasStatus($type, $id, [self::PENDING, self::RISKY]);
    }
}
