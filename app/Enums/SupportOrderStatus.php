<?php

namespace App\Enums;

enum SupportOrderStatus: int
{
    case Pending = 0;
    case Paid = 1;

    public function label(): string
    {
        return match ($this) {
            self::Pending => '待支付',
            self::Paid => '已支付',
        };
    }
}
