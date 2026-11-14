<?php

namespace App\Support;

use App\Models\User;

class DebugUsers
{
    public const OWNER = 'owner@zj.test';

    public const CLUE = 'clue@zj.test';

    public const OPS = 'ops@zj.test';

    public static function emailFor(?string $role): string
    {
        return match ($role) {
            'clue' => self::CLUE,
            'ops' => self::OPS,
            default => self::OWNER,
        };
    }

    public static function roleFor(User $user): string
    {
        return match ($user->email) {
            self::CLUE => 'clue',
            self::OPS => 'ops',
            default => 'owner',
        };
    }

    public static function resolve(?string $role): User
    {
        return User::query()->where('email', self::emailFor($role))->firstOrFail();
    }

    public static function operator(): User
    {
        return self::resolve('ops');
    }
}
