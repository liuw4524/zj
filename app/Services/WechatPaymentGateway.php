<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Enums\RefundStatus;
use App\Exceptions\BusinessException;
use App\Models\EscrowOrder;
use App\Models\Refund;
use App\Models\SupportOrder;
use App\Models\User;
use App\Models\WechatPayment;
use Illuminate\Support\Str;

class WechatPaymentGateway implements PaymentGateway
{
    public function __construct(private WechatPayClient $client) {}

    public function usesWechat(): bool
    {
        return true;
    }

    public function charge(EscrowOrder $order): ?array
    {
        $payment = $this->reusable($order->id, WechatPayment::ESCROW, (int) $order->amount);
        if (! $payment) {
            $payment = $this->make(WechatPayment::ESCROW, (int) $order->amount, $order->id, null);
            $order->out_trade_no = $payment->out_trade_no;
            $order->save();
        }

        return $this->prepay($payment, $order->user, '爪迹酬谢托管');
    }

    public function chargeTopup(EscrowOrder $order, int $addAmount): ?array
    {
        $payment = $this->reusable($order->id, WechatPayment::TOPUP, $addAmount);
        if (! $payment) {
            $payment = $this->make(WechatPayment::TOPUP, $addAmount, $order->id, null);
        }

        return $this->prepay($payment, $order->user, '爪迹追加酬谢');
    }

    public function chargeSupport(SupportOrder $order): ?array
    {
        $payment = WechatPayment::query()
            ->where('support_order_id', $order->id)
            ->where('kind', WechatPayment::SUPPORT)
            ->whereNull('paid_at')
            ->latest('id')
            ->first();
        if (! $payment || (int) $payment->amount !== (int) $order->amount) {
            $payment = $this->make(WechatPayment::SUPPORT, (int) $order->amount, null, $order->id);
            $order->out_trade_no = $payment->out_trade_no;
            $order->save();
        }

        return $this->prepay($payment, $order->user, '爪迹小爪印');
    }

    public function refund(Refund $refund): void
    {
        $order = $refund->escrowOrder()->firstOrFail();
        $payments = WechatPayment::query()
            ->where('escrow_order_id', $order->id)
            ->whereNotNull('paid_at')
            ->orderBy('id')
            ->get();
        $paid = (int) $payments->sum('amount');
        if ($payments->isEmpty() || $paid !== (int) $order->amount || $paid !== (int) $refund->amount) {
            throw new BusinessException('退款金额与微信支付记录不一致');
        }

        foreach ($payments as $payment) {
            try {
                $this->client->refund(
                    $payment->out_trade_no,
                    $this->refundNo($refund, $payment),
                    (int) $payment->amount,
                    (int) $payment->amount,
                );
            } catch (BusinessException $e) {
                if (! str_contains($e->getMessage(), '已全额退款')) {
                    throw $e;
                }
            }
        }

        $refund->status = RefundStatus::Refunded;
        $refund->save();
    }

    private function reusable(int $escrowId, string $kind, int $amount): ?WechatPayment
    {
        return WechatPayment::query()
            ->where('escrow_order_id', $escrowId)
            ->where('kind', $kind)
            ->where('amount', $amount)
            ->whereNull('paid_at')
            ->where('created_at', '>=', now()->subMinutes(30))
            ->latest('id')
            ->first();
    }

    private function make(string $kind, int $amount, ?int $escrowId, ?int $supportId): WechatPayment
    {
        $payment = new WechatPayment;
        $payment->out_trade_no = 'W'.Str::ulid();
        $payment->amount = $amount;
        $payment->kind = $kind;
        $payment->escrow_order_id = $escrowId;
        $payment->support_order_id = $supportId;
        $payment->save();

        return $payment;
    }

    private function prepay(WechatPayment $payment, User $user, string $description): array
    {
        $params = $this->client->jsapi($payment->out_trade_no, (int) $payment->amount, $description, $user);
        $package = (string) ($params['package'] ?? '');
        $payment->prepay_id = str_starts_with($package, 'prepay_id=') ? substr($package, 10) : null;
        $payment->save();

        return $params;
    }

    private function refundNo(Refund $refund, WechatPayment $payment): string
    {
        return substr($refund->out_refund_no, 0, 20).$payment->id;
    }
}
