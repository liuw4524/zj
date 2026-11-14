<?php

namespace App\Contracts;

use App\Models\EscrowOrder;
use App\Models\Refund;
use App\Models\SupportOrder;

interface PaymentGateway
{
    public function usesWechat(): bool;

    public function charge(EscrowOrder $order): ?array;

    public function chargeTopup(EscrowOrder $order, int $addAmount): ?array;

    public function chargeSupport(SupportOrder $order): ?array;

    public function refund(Refund $refund): void;
}
