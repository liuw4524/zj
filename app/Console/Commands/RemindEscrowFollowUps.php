<?php

namespace App\Console\Commands;

use App\Enums\EscrowStatus;
use App\Enums\LeadKind;
use App\Enums\LeadStatus;
use App\Enums\PostStatus;
use App\Models\EscrowOrder;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Setting;
use App\Services\OpsAlert;
use App\Support\Money;
use App\Support\RefundWindow;
use Illuminate\Console\Command;

class RemindEscrowFollowUps extends Command
{
    protected $signature = 'escrows:remind';

    protected $description = '托管待打款和交接超时的运营提醒';

    public function handle(OpsAlert $alerts): int
    {
        $payouts = 0;
        EscrowOrder::query()
            ->with('post')
            ->where('status', EscrowStatus::PendingOffline)
            ->whereNotNull('payout_ready_at')
            ->where('payout_ready_at', '<=', now()->subDay())
            ->orderBy('id')
            ->each(function (EscrowOrder $order) use ($alerts, &$payouts) {
                $days = max(1, (int) abs($order->payout_ready_at->diffInDays(now())));
                $name = $order->post?->pet_name ?: '丢失帖';
                $amount = Money::yuan($order->payee_amount);
                $alerts->once(
                    'escrow-payout-1d:'.$order->id,
                    '打款已等待 1 天',
                    '「'.$name.'」待打款已超过 1 天，应付线索人 '.$amount.'。',
                );
                if ($days >= 3) {
                    $alerts->once(
                        'escrow-payout-3d:'.$order->id,
                        '打款已等待 '.$days.' 天',
                        '「'.$name.'」待打款已超过 '.$days.' 天，应付线索人 '.$amount.'。',
                    );
                }
                $payouts++;
            });

        $hours = max(1, Setting::getInt('contact_visible_hours'));
        $handovers = 0;
        Lead::query()
            ->with('post')
            ->where('kind', LeadKind::Held)
            ->where('status', LeadStatus::Matched)
            ->whereNotNull('matched_at')
            ->where('matched_at', '<=', now()->subHours($hours))
            ->whereHas('post', function ($query) {
                $query->where('status', PostStatus::Handover)
                    ->where('escrow_to_platform', true)
                    ->whereHas('escrow', fn ($escrow) => $escrow->where('status', EscrowStatus::Held));
            })
            ->orderBy('id')
            ->each(function (Lead $lead) use ($alerts, &$handovers) {
                $name = $lead->post?->pet_name ?: '丢失帖';
                $alerts->once(
                    'escrow-handover:'.$lead->id,
                    '交接未确认',
                    '「'.$name.'」交接后仍未确认收到，托管金还在平台。',
                );
                $handovers++;
            });

        $warnings = 0;
        EscrowOrder::query()
            ->with('post')
            ->where('status', EscrowStatus::Held)
            ->whereNotNull('paid_at')
            ->where('paid_at', '<=', now()->subDays(RefundWindow::DAYS - RefundWindow::WARN_DAYS))
            ->where('paid_at', '>', now()->subDays(RefundWindow::DAYS))
            ->whereHas('post', fn ($query) => $query->whereNotIn('status', [
                PostStatus::Withdrawn,
                PostStatus::Closed,
                PostStatus::Rejected,
            ]))
            ->orderBy('id')
            ->each(function (EscrowOrder $order) use (&$warnings) {
                $deadline = RefundWindow::deadline($order);
                $notification = Notification::query()->firstOrCreate(
                    ['dedupe_key' => 'refund-window:'.$order->id],
                    [
                        'user_id' => $order->user_id,
                        'post_id' => $order->post_id,
                        'kind' => 'refund_window',
                        'title' => '托管即将不能原路退回',
                        'body' => ($order->post?->pet_name ?: '这条寻宠').' 的托管将在 '.$deadline?->format('Y年n月j日').' 后不能原路退回。可以现在撤回，也可以继续挂着等找回。',
                    ],
                );
                if ($notification->wasRecentlyCreated) {
                    $warnings++;
                }
            });

        $this->info('打款复查 '.$payouts.' 条，交接超时 '.$handovers.' 条，退款期限 '.$warnings.' 条');

        return self::SUCCESS;
    }
}
