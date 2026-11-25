<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Enums\EscrowStatus;
use App\Enums\LeadStatus;
use App\Enums\PostStatus;
use App\Enums\RefundStatus;
use App\Exceptions\BusinessException;
use App\Models\EscrowOrder;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Refund;
use App\Models\Setting;
use App\Support\Money;
use App\Support\RefundWindow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EscrowService
{
    public function __construct(private PaymentGateway $gateway) {}

    public function refund(EscrowOrder $order): Refund
    {
        if ($order->status === EscrowStatus::Refunded) {
            return $order->refunds()->latest('id')->first();
        }

        if (! in_array($order->status, [EscrowStatus::Held, EscrowStatus::AppealFrozen, EscrowStatus::RefundReview], true)) {
            throw new BusinessException('当前资金状态不能退款');
        }

        $order->status = EscrowStatus::Refunding;
        $order->save();

        $refund = new Refund([
            'amount' => $order->amount,
            'out_refund_no' => 'R'.Str::ulid(),
        ]);
        $refund->escrow_order_id = $order->id;
        $refund->status = RefundStatus::Refunding;
        $refund->save();

        try {
            $this->gateway->refund($refund);
        } catch (BusinessException $e) {
            if (! WechatPaymentGateway::alreadyRefunded($e->getMessage())) {
                throw $e;
            }
            $refund->status = RefundStatus::Refunded;
            $refund->save();
        }
        $refund->refresh();
        if ($refund->status !== RefundStatus::Refunded) {
            return $refund;
        }

        $order->status = EscrowStatus::Refunded;
        $order->save();

        return $refund->refresh();
    }

    public function markRefundedOffline(EscrowOrder $order): EscrowOrder
    {
        return DB::transaction(function () use ($order) {
            $locked = EscrowOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === EscrowStatus::Refunded) {
                return $locked;
            }

            if (! in_array($locked->status, [EscrowStatus::Held, EscrowStatus::AppealFrozen, EscrowStatus::RefundReview], true) || ! RefundWindow::expired($locked)) {
                throw new BusinessException('当前资金状态不能线下退回');
            }

            $refund = new Refund([
                'amount' => $locked->amount,
                'out_refund_no' => 'R'.Str::ulid(),
            ]);
            $refund->escrow_order_id = $locked->id;
            $refund->status = RefundStatus::Refunded;
            $refund->save();

            $locked->status = EscrowStatus::Refunded;
            $locked->save();

            return $locked->refresh();
        });
    }

    public function approveRefund(EscrowOrder $order): EscrowOrder
    {
        if ($order->status !== EscrowStatus::RefundReview) {
            throw new BusinessException('当前资金状态不能同意退款');
        }

        if (RefundWindow::expired($order)) {
            $locked = $this->markRefundedOffline($order);
            $this->tellOwner($locked, '托管金不能原路退回', '已超过微信退款期限，平台会线下把托管金退给你。');

            return $locked;
        }

        $this->refund($order);
        $locked = $order->refresh();
        $this->tellOwner($locked, '托管金已退回', '撤回申请已通过，托管金将原路退回。');

        return $locked;
    }

    public function refuseRefund(EscrowOrder $order): EscrowOrder
    {
        if ($order->status !== EscrowStatus::RefundReview) {
            throw new BusinessException('当前资金状态不能驳回退款');
        }

        $lead = $order->lead ?: $order->post?->leads()->whereNotNull('matched_at')->latest('matched_at')->first();
        if (! $lead) {
            throw new BusinessException('没有可付款的线索人');
        }

        $order->lead_id = $lead->id;
        $order->save();
        $locked = $this->moveToPendingOffline($order, 'refuse');
        app(PostService::class)->closeSettled($locked->post);
        $this->tellClue($lead, '平台将支付酬谢', $this->cluePayBody($locked, '宠主的退款申请未通过。确认交接完成后，酬谢由平台付给你。'));

        return $locked->refresh();
    }

    public function payMatchedClue(EscrowOrder $order): EscrowOrder
    {
        if ($order->status !== EscrowStatus::Held) {
            throw new BusinessException('当前资金状态不能付给线索人');
        }

        $lead = $order->post?->leads()->where('status', LeadStatus::Matched)->latest('matched_at')->first();
        if (! $lead) {
            throw new BusinessException('没有处于找对的线索');
        }

        $order->lead_id = $lead->id;
        $order->save();
        $locked = $this->moveToPendingOffline($order, 'handover');
        app(PostService::class)->closeSettled($locked->post);
        $locked = $locked->refresh();
        $this->tellClue($lead, '平台将支付酬谢', $this->cluePayBody($locked, '平台已确认把酬谢付给你，会线下打款。'));
        $this->tellOwner($locked, '酬谢改付给线索人', '平台已确认交接，托管酬谢将付给线索人，不再退回。');

        return $locked;
    }

    public function moveToPendingOffline(EscrowOrder $order, ?string $alertBody = null): EscrowOrder
    {
        if ($order->commission_amount === null) {
            $rate = Setting::getInt('commission_rate');
            $commission = intdiv($order->amount * $rate, 100);
            $order->commission_rate = $rate;
            $order->commission_amount = $commission;
            $order->payee_amount = $order->amount - $commission;
        }

        if ($order->payout_ready_at === null) {
            $order->payout_ready_at = now();
        }

        $order->status = EscrowStatus::PendingOffline;
        $order->save();

        $name = $order->post?->pet_name ?: '丢失帖';
        $amount = Money::yuan($order->payee_amount);
        $body = match ($alertBody) {
            'refuse' => '「'.$name.'」的退款申请已驳回，改为付给线索人，应付 '.$amount.'。',
            'handover' => '「'.$name.'」交接未确认，改为付给线索人，应付 '.$amount.'。',
            default => $alertBody ?: ('「'.$name.'」已确认收到，应付线索人 '.$amount.'。'),
        };
        app(OpsAlert::class)->once(
            'escrow-payout:'.$order->id,
            '待打款',
            $body,
        );

        $post = $order->post;
        $post->status = PostStatus::PendingSettlement;
        $post->save();

        return $order;
    }

    private function tellOwner(EscrowOrder $order, string $title, string $body): void
    {
        Notification::query()->firstOrCreate(
            ['dedupe_key' => 'escrow-owner:'.$order->id.':'.$order->status->value],
            [
                'user_id' => $order->user_id,
                'post_id' => $order->post_id,
                'kind' => 'escrow_refund_result',
                'title' => $title,
                'body' => $body,
            ],
        );
    }

    private function cluePayBody(EscrowOrder $order, string $body): string
    {
        if ($order->commission_rate === null || $order->payee_amount === null) {
            return $body;
        }

        $payee = Money::yuan($order->payee_amount);

        return $body.'平台将抽取酬谢的 '.$order->commission_rate.'% 作为平台的运营和税费成本，您实际得到的酬谢是 '.$payee.'，请您知悉。';
    }

    private function tellClue(Lead $lead, string $title, string $body): void
    {
        Notification::query()->firstOrCreate(
            ['dedupe_key' => 'escrow-clue:'.$lead->id.':'.$title],
            [
                'user_id' => $lead->user_id,
                'post_id' => $lead->post_id,
                'kind' => 'escrow_pay_clue',
                'title' => $title,
                'body' => $body,
            ],
        );
    }

    public function markSettled(EscrowOrder $order, ?string $note = null): EscrowOrder
    {
        return DB::transaction(function () use ($order, $note) {
            $locked = EscrowOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [
                EscrowStatus::PendingOffline,
                EscrowStatus::Cooling,
                EscrowStatus::AppealFrozen,
            ], true)) {
                throw new BusinessException('当前资金状态不能结清');
            }

            if ($locked->status !== EscrowStatus::PendingOffline) {
                $this->moveToPendingOffline($locked);
                $locked->refresh();
            }

            $note = trim((string) $note);
            if ($note !== '') {
                $locked->payout_note = $note;
            }
            $locked->status = EscrowStatus::Settled;
            $locked->settled_at = now();
            $locked->save();

            app(PostService::class)->closeSettled($locked->post);

            return $locked->refresh();
        });
    }
}
