<?php

namespace App\Enums;

enum ClueContactRequestStatus: int
{
    case Pending = 1;
    case Approved = 2;
    case Rejected = 3;
    case Cancelled = 4;

    public function label(): string
    {
        return match ($this) {
            self::Pending => '待处理',
            self::Approved => '已同意',
            self::Rejected => '已拒绝',
            self::Cancelled => '已取消',
        };
    }
}
