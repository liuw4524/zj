<?php

namespace App\Models;

use App\Enums\ClueContactRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClueContactRequest extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ClueContactRequestStatus::class,
        ];
    }

    public function cluePost(): BelongsTo
    {
        return $this->belongsTo(CluePost::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
