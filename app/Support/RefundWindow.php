<?php

namespace App\Support;

use App\Models\EscrowOrder;
use Illuminate\Support\Carbon;

class RefundWindow
{
    public const DAYS = 365;

    public const WARN_DAYS = 30;

    public static function deadline(EscrowOrder $order): ?Carbon
    {
        return $order->paid_at?->copy()->addDays(self::DAYS);
    }

    public static function expired(EscrowOrder $order): bool
    {
        $deadline = self::deadline($order);

        return $deadline !== null && $deadline->lessThanOrEqualTo(now());
    }

    public static function nearing(EscrowOrder $order): bool
    {
        $deadline = self::deadline($order);
        if ($deadline === null || self::expired($order)) {
            return false;
        }

        return $deadline->lessThanOrEqualTo(now()->addDays(self::WARN_DAYS));
    }
}
