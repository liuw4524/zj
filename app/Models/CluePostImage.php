<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CluePostImage extends Model
{
    protected $fillable = ['path', 'sort'];

    public function cluePost(): BelongsTo
    {
        return $this->belongsTo(CluePost::class);
    }
}
