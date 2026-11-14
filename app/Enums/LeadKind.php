<?php

namespace App\Enums;

enum LeadKind: int
{
    case Held = 0;
    case Seen = 1;

    public function label(): string
    {
        return match ($this) {
            self::Held => '宠物在我这里',
            self::Seen => '只是看到',
        };
    }

    public function apiValue(): string
    {
        return match ($this) {
            self::Held => 'held',
            self::Seen => 'seen',
        };
    }

    public static function fromApi(string $value): self
    {
        return match ($value) {
            'seen' => self::Seen,
            default => self::Held,
        };
    }
}
