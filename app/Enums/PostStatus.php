<?php

namespace App\Enums;

enum PostStatus: int
{
    case PendingPayment = 0;
    case PendingReview = 1;
    case Showing = 2;
    case Frozen = 3;
    case Handover = 4;
    case Cooling = 5;
    case PendingSettlement = 6;
    case Closed = 7;
    case Withdrawn = 8;
    case Rejected = 9;

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => '待支付',
            self::PendingReview => '待审核',
            self::Showing => '展示中',
            self::Frozen => '已下架',
            self::Handover => '交接中',
            self::Cooling => '冷静期',
            self::PendingSettlement => '待线下结清',
            self::Closed => '已结案',
            self::Withdrawn => '已撤回',
            self::Rejected => '已驳回',
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
