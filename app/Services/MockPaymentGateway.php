<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Enums\EscrowStatus;
use App\Enums\RefundStatus;
use App\Models\EscrowOrder;
use App\Models\Refund;
use App\Models\SupportOrder;

class MockPaymentGateway implements PaymentGateway
{
    public function usesWechat(): bool
    {
        return false;
    }

    public function charge(EscrowOrder $order): ?array
    {
        $order->status = EscrowStatus::Held;
        if ($order->paid_at === null) {
            $order->paid_at = now();
        }
        $order->save();

        return null;
    }

    public function chargeTopup(EscrowOrder $order, int $addAmount): ?array
    {
        return null;
    }

    public function chargeSupport(SupportOrder $order): ?array
    {
        return null;
    }

    public function refund(Refund $refund): void
    {
        $refund->status = RefundStatus::Refunded;
        $refund->save();
    }
}
