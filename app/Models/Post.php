<?php

namespace App\Models;

use App\Enums\PostStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Post extends Model
{
    protected $fillable = [
        'user_id',
        'pet_name',
        'breed',
        'sex',
        'description',
        'pet_traits',
        'province',
        'city',
        'district',
        'town',
        'lost_place',
        'lost_story',
        'lost_at',
        'reward_amount',
        'reward_topped_up',
        'escrow_to_platform',
        'contact_name',
        'contact_phone',
        'reject_reason',
        'view_count',
        'share_count',
        'forwarded_wechat',
        'forwarded_douyin',
        'forwarded_rescue_team',
    ];

    protected function casts(): array
    {
        return [
            'lost_at' => 'datetime',
            'lit_until' => 'datetime',
            'status' => PostStatus::class,
            'reward_amount' => 'integer',
            'reward_topped_up' => 'boolean',
            'escrow_to_platform' => 'boolean',
            'view_count' => 'integer',
            'share_count' => 'integer',
            'forwarded_wechat' => 'boolean',
            'forwarded_douyin' => 'boolean',
            'forwarded_rescue_team' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(PostImage::class)->orderBy('sort');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function escrow(): HasOne
    {
        return $this->hasOne(EscrowOrder::class);
    }

    public function appeals(): HasMany
    {
        return $this->hasMany(Appeal::class);
    }

    public function isPubliclyVisible(): bool
    {
        return $this->status === PostStatus::Showing
            && $this->lit_until !== null
            && $this->lit_until->isFuture();
    }
}
