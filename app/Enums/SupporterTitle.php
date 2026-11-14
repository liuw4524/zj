<?php

namespace App\Enums;

use App\Models\User;

enum SupporterTitle: string
{
    case NewSupporter = 'new';
    case Warm = 'warm';
    case Guard = 'guard';
    case Legend = 'legend';

    public function label(): string
    {
        return match ($this) {
            self::NewSupporter => '支持新爪',
            self::Warm => '热心爪',
            self::Guard => '守护爪',
            self::Legend => '传奇爪',
        };
    }

    public static function fromPaws(int $paws): ?self
    {
        if ($paws >= 500) {
            return self::Legend;
        }
        if ($paws >= 100) {
            return self::Guard;
        }
        if ($paws >= 30) {
            return self::Warm;
        }
        if ($paws >= 1) {
            return self::NewSupporter;
        }

        return null;
    }

    public static function payload(User $user): array
    {
        $paws = (int) $user->support_paws;

        return [
            'paws' => $paws,
            'title' => self::fromPaws($paws)?->label(),
        ];
    }
}
