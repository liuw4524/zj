<?php

namespace App\Models;

use App\Enums\LeadKind;
use App\Enums\LeadStatus;
use App\Models\ContentCheck;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    protected $fillable = [
        'post_id',
        'user_id',
        'content',
        'kind',
        'contact_name',
        'contact_phone',
    ];

    protected function casts(): array
    {
        return [
            'kind' => LeadKind::class,
            'status' => LeadStatus::class,
            'matched_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'contact_visible_until' => 'datetime',
            'owner_seen_at' => 'datetime',
        ];
    }

    public function statusLabel(): string
    {
        if ($this->kind === LeadKind::Seen && $this->status === LeadStatus::Rejected) {
            return '与本案无关';
        }

        if ($this->status === LeadStatus::ClosedWithCase && $this->confirmed_at !== null) {
            return '已确认收到';
        }

        return $this->status->label();
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(LeadImage::class)->orderBy('sort');
    }

    public function receiptImages(): HasMany
    {
        return $this->hasMany(ReceiptImage::class)->orderBy('sort');
    }

    public function scopeClearedForOwner(Builder $query): void
    {
        $query->whereNotExists(function ($sub) {
            $sub->selectRaw('1')
                ->from('content_checks')
                ->whereColumn('content_checks.subject_id', 'leads.id')
                ->where('content_checks.subject_type', ContentCheck::LEAD)
                ->whereIn('content_checks.status', [ContentCheck::PENDING, ContentCheck::RISKY]);
        });
    }
}
