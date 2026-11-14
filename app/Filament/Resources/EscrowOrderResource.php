<?php

namespace App\Filament\Resources;

use App\Enums\EscrowStatus;
use App\Enums\LeadStatus;
use App\Enums\PostStatus;
use App\Models\EscrowOrder;
use App\Models\Lead;
use App\Services\EscrowService;
use App\Services\PostService;
use App\Support\Money;
use App\Support\RefundWindow;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EscrowOrderResource extends Resource
{
    protected static ?string $model = EscrowOrder::class;

    protected static ?string $navigationLabel = '资金单';

    protected static ?string $modelLabel = '资金单';

    protected static ?string $pluralModelLabel = '资金单';

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 40;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::actionableCount();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'post.leads',
            'lead.receiptImages',
            'user',
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\TextEntry::make('post.trace_code')->label('爪迹编号')->placeholder('未生成'),
            Infolists\Components\TextEntry::make('post.pet_name')->label('宠物'),
            Infolists\Components\TextEntry::make('post.city')->label('城市'),
            Infolists\Components\TextEntry::make('status')
                ->label('资金状态')
                ->formatStateUsing(fn (EscrowStatus $state) => $state->label()),
            Infolists\Components\TextEntry::make('post.status')
                ->label('帖子状态')
                ->formatStateUsing(fn (PostStatus $state) => $state->label()),
            Infolists\Components\TextEntry::make('amount')
                ->label('托管金额')
                ->formatStateUsing(fn ($state) => Money::yuan($state)),
            Infolists\Components\TextEntry::make('commission_amount')
                ->label('佣金')
                ->placeholder('—')
                ->formatStateUsing(fn ($state) => Money::yuan($state)),
            Infolists\Components\TextEntry::make('payee_amount')
                ->label('应付线索人')
                ->placeholder('—')
                ->formatStateUsing(fn ($state) => Money::yuan($state)),
            Infolists\Components\TextEntry::make('post.contact_name')->label('宠主'),
            Infolists\Components\TextEntry::make('post.contact_phone')->label('宠主电话'),
            Infolists\Components\TextEntry::make('clue_name')
                ->label('线索人')
                ->state(fn (EscrowOrder $record) => static::clueLead($record)?->contact_name)
                ->placeholder('—'),
            Infolists\Components\TextEntry::make('clue_phone')
                ->label('线索人电话')
                ->state(fn (EscrowOrder $record) => static::clueLead($record)?->contact_phone)
                ->placeholder('—'),
            Infolists\Components\TextEntry::make('ops_note')->label('跟进备注')->placeholder('无')->columnSpanFull(),
            Infolists\Components\TextEntry::make('payout_note')->label('打款备注')->placeholder('无'),
            Infolists\Components\TextEntry::make('progress')
                ->label('进度')
                ->state(fn (EscrowOrder $record) => static::progressLines($record))
                ->listWithLineBreaks()
                ->columnSpanFull(),
            Infolists\Components\ImageEntry::make('receipts')
                ->label('接收照片')
                ->disk('public')
                ->height(120)
                ->visible(fn (EscrowOrder $record) => static::receiptPaths($record) !== [])
                ->getStateUsing(fn (EscrowOrder $record) => static::receiptPaths($record))
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordUrl(fn (EscrowOrder $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('post.trace_code')->label('爪迹编号')->placeholder('未生成'),
                Tables\Columns\TextColumn::make('post.pet_name')->label('宠物')->searchable(),
                Tables\Columns\TextColumn::make('post.city')->label('城市'),
                Tables\Columns\TextColumn::make('amount')
                    ->label('托管')
                    ->formatStateUsing(fn ($state) => Money::yuan($state)),
                Tables\Columns\TextColumn::make('commission_amount')
                    ->label('佣金')
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state) => Money::yuan($state)),
                Tables\Columns\TextColumn::make('payee_amount')
                    ->label('应付线索人')
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state) => Money::yuan($state)),
                Tables\Columns\TextColumn::make('status')
                    ->label('状态')
                    ->formatStateUsing(fn (EscrowStatus $state) => $state->label()),
                Tables\Columns\TextColumn::make('post.contact_phone')->label('宠主电话'),
                Tables\Columns\TextColumn::make('clue_phone')
                    ->label('线索人电话')
                    ->state(fn (EscrowOrder $record) => static::clueLead($record)?->contact_phone)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('waiting')
                    ->label('等待')
                    ->state(fn (EscrowOrder $record) => static::waitingLabel($record))
                    ->color(fn (EscrowOrder $record) => static::waitingColor($record)),
            ])
            ->defaultSort('id', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make()->label('详情'),
                Tables\Actions\Action::make('approve')
                    ->label('通过')
                    ->visible(fn (EscrowOrder $record) => $record->post?->status === PostStatus::PendingReview)
                    ->action(fn (EscrowOrder $record) => app(PostService::class)->approve($record->post)),
                Tables\Actions\Action::make('reject')
                    ->label('驳回退款')
                    ->visible(fn (EscrowOrder $record) => $record->post?->status === PostStatus::PendingReview)
                    ->form([
                        Forms\Components\Textarea::make('reason')->label('原因')->required()->maxLength(200),
                    ])
                    ->action(fn (EscrowOrder $record, array $data) => app(PostService::class)->reject($record->post, $data['reason'])),
                Tables\Actions\Action::make('follow')
                    ->label('跟进')
                    ->visible(fn (EscrowOrder $record) => ! in_array($record->status, [EscrowStatus::Settled, EscrowStatus::Refunded], true))
                    ->fillForm(fn (EscrowOrder $record) => ['ops_note' => $record->ops_note])
                    ->form([
                        Forms\Components\Textarea::make('ops_note')->label('备注')->required()->maxLength(200),
                    ])
                    ->action(function (EscrowOrder $record, array $data) {
                        $record->ops_note = $data['ops_note'];
                        $record->ops_followed_at = now();
                        $record->save();
                    }),
                Tables\Actions\Action::make('payClue')
                    ->label('改为付给线索人')
                    ->visible(fn (EscrowOrder $record) => $record->status === EscrowStatus::Held
                        && $record->post?->status === PostStatus::Handover)
                    ->requiresConfirmation()
                    ->modalDescription('确认后这笔托管进入待打款，由你线下付给线索人，不再退给宠主。')
                    ->action(fn (EscrowOrder $record) => app(EscrowService::class)->payMatchedClue($record)),
                Tables\Actions\Action::make('approveRefund')
                    ->label('同意退款')
                    ->visible(fn (EscrowOrder $record) => $record->status === EscrowStatus::RefundReview)
                    ->requiresConfirmation()
                    ->modalDescription('确认后才向微信发起原路退款。已超过 365 天的改为线下退回。')
                    ->action(fn (EscrowOrder $record) => app(EscrowService::class)->approveRefund($record)),
                Tables\Actions\Action::make('refuseRefund')
                    ->label('驳回并付给线索人')
                    ->visible(fn (EscrowOrder $record) => $record->status === EscrowStatus::RefundReview)
                    ->requiresConfirmation()
                    ->modalDescription('驳回后这笔托管改为付给线索人，不再退给宠主。')
                    ->action(fn (EscrowOrder $record) => app(EscrowService::class)->refuseRefund($record)),
                Tables\Actions\Action::make('refundOffline')
                    ->label('已线下退回')
                    ->visible(fn (EscrowOrder $record) => $record->status === EscrowStatus::Held
                        && $record->post?->status === PostStatus::Withdrawn
                        && RefundWindow::expired($record))
                    ->requiresConfirmation()
                    ->modalDescription('确认已经把托管金线下退给宠主。')
                    ->action(fn (EscrowOrder $record) => app(EscrowService::class)->markRefundedOffline($record)),
                Tables\Actions\Action::make('settle')
                    ->label('已线下结清')
                    ->visible(fn (EscrowOrder $record) => in_array($record->status, [
                        EscrowStatus::PendingOffline,
                        EscrowStatus::Cooling,
                        EscrowStatus::AppealFrozen,
                    ], true))
                    ->form([
                        Forms\Components\TextInput::make('payout_note')
                            ->label('打款备注')
                            ->placeholder('例如已微信转账')
                            ->required()
                            ->maxLength(100),
                    ])
                    ->action(fn (EscrowOrder $record, array $data) => app(EscrowService::class)->markSettled($record, $data['payout_note'])),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => EscrowOrderResource\Pages\ListEscrowOrders::route('/'),
            'view' => EscrowOrderResource\Pages\ViewEscrowOrder::route('/{record}'),
        ];
    }

    public static function actionableCount(): int
    {
        return EscrowOrder::query()
            ->where(function (Builder $query) {
                $query->whereIn('status', [EscrowStatus::PendingOffline, EscrowStatus::RefundReview])
                    ->orWhere(function (Builder $review) {
                        $review->where('status', EscrowStatus::Held)
                            ->whereHas('post', fn (Builder $post) => $post->where('status', PostStatus::PendingReview));
                    });
            })
            ->count();
    }

    public static function clueLead(EscrowOrder $order): ?Lead
    {
        if ($order->lead) {
            return $order->lead;
        }

        return $order->post?->leads
            ->first(fn (Lead $lead) => $lead->status === LeadStatus::Matched);
    }

    public static function waitingLabel(EscrowOrder $order): string
    {
        $start = static::waitingFrom($order);
        if (! $start) {
            return '—';
        }

        $hours = (int) abs($start->diffInHours(now()));
        if ($hours < 1) {
            return '刚刚';
        }
        if ($hours < 24) {
            return $hours.' 小时';
        }

        return intdiv($hours, 24).' 天';
    }

    public static function waitingColor(EscrowOrder $order): ?string
    {
        $start = static::waitingFrom($order);
        if (! $start) {
            return null;
        }

        $hours = (int) abs($start->diffInHours(now()));
        if ($hours >= 72) {
            return 'danger';
        }
        if ($hours >= 24) {
            return 'warning';
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public static function progressLines(EscrowOrder $order): array
    {
        $lead = static::clueLead($order);
        $lines = [];
        $lines[] = '支付：'.($order->paid_at ? $order->paid_at->format('Y-m-d H:i') : '未支付');
        $lines[] = '帖子：'.($order->post?->status?->label() ?: '未知');
        $lines[] = '找对：'.($lead?->matched_at ? $lead->matched_at->format('Y-m-d H:i') : '未找对');
        $lines[] = '确认收到：'.($order->payout_ready_at ? $order->payout_ready_at->format('Y-m-d H:i') : '未确认');
        if ($order->status === EscrowStatus::RefundReview) {
            $lines[] = '退款：待审批';
        } elseif ($order->status === EscrowStatus::Refunded) {
            $lines[] = '退款：已退回';
        } elseif ($order->status === EscrowStatus::Held && $order->post?->status === PostStatus::Withdrawn && RefundWindow::expired($order)) {
            $lines[] = '退款：已超过微信期限，待线下退回';
        }
        $lines[] = '结清：'.($order->settled_at ? $order->settled_at->format('Y-m-d H:i') : '未结清');

        return $lines;
    }

    /**
     * @return array<int, string>
     */
    public static function receiptPaths(EscrowOrder $order): array
    {
        $lead = $order->lead ?: static::clueLead($order);

        return $lead?->receiptImages->pluck('path')->all() ?? [];
    }

    private static function waitingFrom(EscrowOrder $order): ?\Illuminate\Support\Carbon
    {
        if ($order->status === EscrowStatus::PendingOffline) {
            return $order->payout_ready_at;
        }

        if ($order->status === EscrowStatus::Held && $order->post?->status === PostStatus::PendingReview) {
            return $order->paid_at;
        }

        if ($order->status === EscrowStatus::Held && $order->post?->status === PostStatus::Handover) {
            return static::clueLead($order)?->matched_at;
        }

        return null;
    }
}
