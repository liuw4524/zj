<?php

namespace App\Models;

use App\Enums\CluePostStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CluePost extends Model
{
    protected function casts(): array
    {
        return [
            'found_at' => 'datetime',
            'lit_until' => 'datetime',
            'delisted_at' => 'datetime',
            'status' => CluePostStatus::class,
            'contact_public' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(CluePostImage::class)->orderBy('sort');
    }

    public function contactRequests(): HasMany
    {
        return $this->hasMany(ClueContactRequest::class);
    }

    public function isPubliclyVisible(): bool
    {
        return $this->status === CluePostStatus::Showing
            && $this->lit_until !== null
            && $this->lit_until->isFuture();
    }
}
