<?php

namespace App\Services;

use App\Enums\SupporterTitle;
use App\Models\User;
use App\Support\DebugUsers;

class ProfileService
{
    public function update(User $user, array $data): User
    {
        $user->name = $data['name'];
        $user->phone = $data['phone'];
        foreach (['province', 'city', 'district', 'address'] as $key) {
            if (array_key_exists($key, $data)) {
                $user->{$key} = $data[$key];
            }
        }
        $user->save();

        return $user->refresh();
    }

    public function payload(User $user, array $medal): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'province' => $user->province,
            'city' => $user->city,
            'district' => $user->district,
            'address' => $user->address,
            'is_blocked' => (bool) $user->is_blocked,
            'debug_role' => config('app.auth_disabled') ? DebugUsers::roleFor($user) : null,
            'medal' => $medal,
            'supporter' => SupporterTitle::payload($user),
        ];
    }
}
