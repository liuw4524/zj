<?php

namespace App\Console\Commands;

use App\Enums\EscrowStatus;
use App\Models\EscrowOrder;
use App\Services\EscrowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FinishCoolingEscrows extends Command
{
    protected $signature = 'escrows:finish-cooling';

    protected $description = '冷静期结束且未申诉的托管单进入待线下结清';

    public function handle(EscrowService $escrows): int
    {
        $ids = EscrowOrder::query()
            ->where('status', EscrowStatus::Cooling)
            ->whereNotNull('settle_due_at')
            ->where('settle_due_at', '<=', now())
            ->pluck('id');

        $count = 0;
        foreach ($ids as $id) {
            DB::transaction(function () use ($escrows, $id, &$count) {
                $order = EscrowOrder::query()->whereKey($id)->lockForUpdate()->first();
                if (! $order || $order->status !== EscrowStatus::Cooling || $order->settle_due_at->isFuture()) {
                    return;
                }
                $escrows->moveToPendingOffline($order);
                $count++;
            });
        }

        $this->info('结转 '.$count.' 条');

        return self::SUCCESS;
    }
}
