<?php

namespace App\Services;

use App\Support\DebugUsers;
use Illuminate\Support\Str;

class OpsAlert
{
    public function once(string $dedupe, string $title, string $body): void
    {
        $ops = DebugUsers::operator();
        $exists = $ops->notifications()->where('data->dedupe', $dedupe)->exists();
        if ($exists) {
            return;
        }

        $ops->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'ops.escrow',
            'data' => [
                'title' => $title,
                'body' => $body,
                'format' => 'filament',
                'duration' => 'persistent',
                'icon' => 'heroicon-o-bell-alert',
                'dedupe' => $dedupe,
            ],
        ]);
    }
}
