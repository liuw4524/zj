<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RuleSection extends Model
{
    protected $fillable = [
        'title',
        'body',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
        ];
    }
}
