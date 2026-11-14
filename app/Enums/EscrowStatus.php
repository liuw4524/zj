<?php

namespace App\Enums;

enum EscrowStatus: int
{
    case Held = 0;
    case Refunding = 1;
    case Refunded = 2;
    case Cooling = 3;
    case AppealFrozen = 4;
    case PendingOffline = 5;
    case Settled = 6;
    case RefundReview = 7;
    case Unpaid = 8;

    public function label(): string
    {
        return match ($this) {
            self::Held => '已托管',
            self::Refunding => '退款中',
            self::Refunded => '已退款',
            self::Cooling => '冷静期',
            self::AppealFrozen => '申诉冻结',
            self::PendingOffline => '待线下结清',
            self::Settled => '已线下结清',
            self::RefundReview => '待退款',
            self::Unpaid => '待支付',
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
