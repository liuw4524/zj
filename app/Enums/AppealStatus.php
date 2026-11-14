<?php

namespace App\Enums;

enum AppealStatus: int
{
    case Pending = 0;
    case Released = 1;
    case Upheld = 2;

    public function label(): string
    {
        return match ($this) {
            self::Pending => '待处理',
            self::Released => '已放行',
            self::Upheld => '申诉成立',
        };
    }

    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
