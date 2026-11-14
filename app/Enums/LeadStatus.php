<?php

namespace App\Enums;

enum LeadStatus: int
{
    case Submitted = 0;
    case Matched = 1;
    case Rejected = 2;
    case HandoverFailed = 3;
    case ClosedWithCase = 4;
    case Noted = 5;

    public function label(): string
    {
        return match ($this) {
            self::Submitted => '已提交',
            self::Matched => '找对',
            self::Rejected => '找错',
            self::HandoverFailed => '交接失败',
            self::ClosedWithCase => '已随结案关闭',
            self::Noted => '已了解',
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
