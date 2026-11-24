<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    protected $fillable = [
        'user_id',
        'post_id',
        'clue_post_id',
        'kind',
        'title',
        'body',
        'dedupe_key',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function cluePost(): BelongsTo
    {
        return $this->belongsTo(CluePost::class);
    }

    public function groupKey(): string
    {
        if ($this->clue_post_id) {
            return $this->kind.':clue:'.$this->clue_post_id;
        }
        if ($this->post_id) {
            return $this->kind.':post:'.$this->post_id;
        }

        return 'row:'.$this->id;
    }

    public function scopeSameGroup(Builder $query, self $notification): void
    {
        $query->where('user_id', $notification->user_id)->where('kind', $notification->kind);

        if ($notification->clue_post_id) {
            $query->where('clue_post_id', $notification->clue_post_id);

            return;
        }
        if ($notification->post_id) {
            $query->where('post_id', $notification->post_id)->whereNull('clue_post_id');

            return;
        }

        $query->whereKey($notification->id);
    }
}
