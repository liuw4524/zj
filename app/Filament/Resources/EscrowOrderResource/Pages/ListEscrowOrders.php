<?php

namespace App\Filament\Resources\EscrowOrderResource\Pages;

use App\Enums\EscrowStatus;
use App\Enums\PostStatus;
use App\Filament\Resources\EscrowOrderResource;
use App\Models\EscrowOrder;
use App\Support\RefundWindow;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListEscrowOrders extends ListRecords
{
    protected static string $resource = EscrowOrderResource::class;

    public function getDefaultActiveTab(): string|int|null
    {
        return 'payout';
    }

    public function getTabs(): array
    {
        return [
            'refund' => Tab::make('待退款')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', EscrowStatus::RefundReview))
                ->badge(fn () => $this->badge(EscrowOrder::query()->where('status', EscrowStatus::RefundReview)->count())),
            'payout' => Tab::make('待打款')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', EscrowStatus::PendingOffline))
                ->badge(fn () => $this->badge(EscrowOrder::query()->where('status', EscrowStatus::PendingOffline)->count())),
            'review' => Tab::make('待审核入账')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('status', EscrowStatus::Held)
                    ->whereHas('post', fn (Builder $post) => $post->where('status', PostStatus::PendingReview)))
                ->badge(fn () => $this->badge(
                    EscrowOrder::query()
                        ->where('status', EscrowStatus::Held)
                        ->whereHas('post', fn (Builder $post) => $post->where('status', PostStatus::PendingReview))
                        ->count()
                )),
            'handover' => Tab::make('交接中')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('status', EscrowStatus::Held)
                    ->whereHas('post', fn (Builder $post) => $post->where('status', PostStatus::Handover))),
            'exception' => Tab::make('异常')
                ->modifyQueryUsing(fn (Builder $query) => $query->where(function (Builder $query) {
                    $query->whereIn('status', [
                        EscrowStatus::Refunding,
                        EscrowStatus::AppealFrozen,
                        EscrowStatus::Cooling,
                    ])->orWhere(function (Builder $refunded) {
                        $refunded->where('status', EscrowStatus::Refunded)
                            ->where('updated_at', '>=', now()->subDays(7));
                    })->orWhere(function (Builder $overdue) {
                        $overdue->where('status', EscrowStatus::Held)
                            ->whereNotNull('paid_at')
                            ->where('paid_at', '<=', now()->subDays(RefundWindow::DAYS))
                            ->whereHas('post', fn (Builder $post) => $post->where('status', PostStatus::Withdrawn));
                    });
                })),
            'done' => Tab::make('已完成')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [
                    EscrowStatus::Settled,
                    EscrowStatus::Refunded,
                ])),
            'all' => Tab::make('全部'),
        ];
    }

    private function badge(int $count): ?string
    {
        return $count > 0 ? (string) $count : null;
    }
}
