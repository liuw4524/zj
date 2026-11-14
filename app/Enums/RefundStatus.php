<?php

namespace App\Enums;

enum RefundStatus: int
{
    case Refunding = 0;
    case Refunded = 1;

    public function label(): string
    {
        return match ($this) {
            self::Refunding => '退款中',
            self::Refunded => '已退款',
        };
    }
}
