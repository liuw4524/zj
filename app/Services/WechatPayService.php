<?php

namespace App\Services;

use App\Enums\EscrowStatus;
use App\Enums\RefundStatus;
use App\Enums\SupportLogSource;
use App\Enums\SupportOrderStatus;
use App\Exceptions\BusinessException;
use App\Models\EscrowOrder;
use App\Models\Post;
use App\Models\Refund;
use App\Models\SupportLog;
use App\Models\SupportOrder;
use App\Models\User;
use App\Models\WechatPayment;
use Illuminate\Support\Facades\DB;

class WechatPayService
{
    public function __construct(private PostService $posts) {}

    public function handle(array $notice): void
    {
        $event = $notice['event_type'];
        $resource = $notice['resource'];
        if (str_starts_with($event, 'TRANSACTION.')) {
            $this->paid($resource);

            return;
        }
        if (str_starts_with($event, 'REFUND.')) {
            $this->refunded($resource);
        }
    }

    private function paid(array $resource): void
    {
        if (($resource['trade_state'] ?? '') !== 'SUCCESS') {
            return;
        }

        $outTradeNo = (string) ($resource['out_trade_no'] ?? '');
        $amount = (int) ($resource['amount']['total'] ?? 0);
        DB::transaction(function () use ($outTradeNo, $amount, $resource) {
            $payment = WechatPayment::query()->where('out_trade_no', $outTradeNo)->lockForUpdate()->first();
            if (! $payment) {
                throw new BusinessException('支付单不存在');
            }
            if ($payment->paid_at) {
                return;
            }
            if ((int) $payment->amount !== $amount) {
                throw new BusinessException('支付金额不一致');
            }

            $payment->paid_at = now();
            $payment->transaction_id = (string) ($resource['transaction_id'] ?? '');
            $payment->save();

            if ($payment->kind === WechatPayment::ESCROW) {
                $this->markEscrowPaid($payment);
            } elseif ($payment->kind === WechatPayment::TOPUP) {
                $this->applyTopup($payment);
            } elseif ($payment->kind === WechatPayment::SUPPORT) {
                $this->markSupportPaid($payment);
            }
        });

        if ($payment = WechatPayment::query()->where('out_trade_no', $outTradeNo)->first()) {
            if ($payment->kind === WechatPayment::ESCROW && $payment->escrow_order_id) {
                $order = EscrowOrder::query()->find($payment->escrow_order_id);
                if ($order) {
                    $this->posts->finishEscrowPayment($order);
                }
            }
        }
    }

    private function markEscrowPaid(WechatPayment $payment): void
    {
        $order = EscrowOrder::query()->whereKey($payment->escrow_order_id)->lockForUpdate()->first();
        if (! $order || $order->paid_at) {
            return;
        }
        $order->status = EscrowStatus::Held;
        $order->paid_at = now();
        $order->save();
    }

    private function applyTopup(WechatPayment $payment): void
    {
        $order = EscrowOrder::query()->whereKey($payment->escrow_order_id)->lockForUpdate()->first();
        $post = $order ? Post::query()->whereKey($order->post_id)->lockForUpdate()->first() : null;
        if (! $order || ! $post || ! $order->paid_at) {
            throw new BusinessException('追加酬谢无法入账');
        }
        $post->reward_amount = (int) $post->reward_amount + (int) $payment->amount;
        $post->reward_topped_up = true;
        $post->save();
        $order->amount = (int) $order->amount + (int) $payment->amount;
        $order->save();
    }

    private function markSupportPaid(WechatPayment $payment): void
    {
        $order = SupportOrder::query()->whereKey($payment->support_order_id)->lockForUpdate()->first();
        if (! $order || $order->paid_at) {
            return;
        }
        $user = User::query()->whereKey($order->user_id)->lockForUpdate()->firstOrFail();
        $next = (int) $user->support_paws + (int) $order->paw_count;
        $user->support_paws = $next;
        $user->save();
        $order->status = SupportOrderStatus::Paid;
        $order->paid_at = now();
        $order->save();
        SupportLog::query()->create([
            'user_id' => $user->id,
            'paws' => $order->paw_count,
            'balance_after' => $next,
            'source' => SupportLogSource::Payment,
            'support_order_id' => $order->id,
            'note' => null,
            'operator_id' => null,
        ]);
    }

    private function refunded(array $resource): void
    {
        if (($resource['refund_status'] ?? '') !== 'SUCCESS') {
            return;
        }
        $payment = WechatPayment::query()->where('out_trade_no', (string) ($resource['out_trade_no'] ?? ''))->first();
        $refund = $payment?->escrowOrder?->refunds()->latest('id')->first();
        if (! $refund) {
            return;
        }
        DB::transaction(function () use ($refund) {
            $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->first();
            $order = $locked?->escrowOrder()->lockForUpdate()->first();
            if (! $locked || ! $order || $order->status === EscrowStatus::Refunded) {
                return;
            }
            $locked->status = RefundStatus::Refunded;
            $locked->save();
            $order->status = EscrowStatus::Refunded;
            $order->save();
        });
    }
}
