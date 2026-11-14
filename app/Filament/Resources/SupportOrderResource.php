<?php

namespace App\Filament\Resources;

use App\Enums\SupporterTitle;
use App\Enums\SupportOrderStatus;
use App\Models\Setting;
use App\Models\SupportLog;
use App\Models\SupportOrder;
use App\Support\Money;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SupportOrderResource extends Resource
{
    protected static ?string $model = SupportOrder::class;

    protected static ?string $navigationLabel = '支持爪迹';

    protected static ?string $modelLabel = '支持订单';

    protected static ?string $pluralModelLabel = '支持订单';

    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?int $navigationSort = 42;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'paymentLog']);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\TextEntry::make('user.name')->label('姓名')->placeholder('未知'),
            Infolists\Components\TextEntry::make('user.phone')->label('手机号')->placeholder('未绑定'),
            Infolists\Components\TextEntry::make('paw_count')->label('本次爪印'),
            Infolists\Components\TextEntry::make('amount')
                ->label('金额')
                ->formatStateUsing(fn ($state) => Money::yuan($state)),
            Infolists\Components\IconEntry::make('anonymous')->label('匿名')->boolean(),
            Infolists\Components\TextEntry::make('status')
                ->label('状态')
                ->formatStateUsing(fn (SupportOrderStatus $state) => $state->label()),
            Infolists\Components\TextEntry::make('paid_at')->label('支付时间')->dateTime('Y-m-d H:i'),
            Infolists\Components\TextEntry::make('out_trade_no')->label('支付单号'),
            Infolists\Components\TextEntry::make('balance_after')
                ->label('入账后累计爪印')
                ->state(fn (SupportOrder $record) => $record->paymentLog?->balance_after)
                ->placeholder('无入账记录'),
            Infolists\Components\TextEntry::make('supporter_title')
                ->label('当时标识')
                ->state(function (SupportOrder $record) {
                    $paws = $record->paymentLog?->balance_after;

                    return $paws === null ? null : SupporterTitle::fromPaws((int) $paws)?->label();
                })
                ->placeholder('无'),
            Infolists\Components\TextEntry::make('payment_log')
                ->label('爪印记录')
                ->state(fn (SupportOrder $record) => static::logLine($record->paymentLog))
                ->placeholder('无入账记录')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordUrl(fn (SupportOrder $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('用户')->searchable()->placeholder('未知'),
                Tables\Columns\TextColumn::make('user.phone')->label('手机号')->searchable()->placeholder('未绑定'),
                Tables\Columns\TextColumn::make('paw_count')->label('爪印'),
                Tables\Columns\TextColumn::make('amount')
                    ->label('金额')
                    ->formatStateUsing(fn ($state) => Money::yuan($state)),
                Tables\Columns\IconColumn::make('anonymous')->label('匿名')->boolean(),
                Tables\Columns\TextColumn::make('status')
                    ->label('状态')
                    ->formatStateUsing(fn (SupportOrderStatus $state) => $state->label()),
                Tables\Columns\TextColumn::make('paid_at')->label('时间')->dateTime('Y-m-d H:i'),
                Tables\Columns\TextColumn::make('out_trade_no')->label('支付单号'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('anonymous')->label('匿名'),
                Tables\Filters\Filter::make('paid_on')
                    ->label('支付时间')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('从'),
                        Forms\Components\DatePicker::make('until')->label('到'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('paid_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('paid_at', '<=', $date));
                    }),
            ])
            ->headerActions([
                Tables\Actions\Action::make('toggleWall')
                    ->label(fn () => Setting::getInt('support_wall_enabled') === 1 ? '关闭支持墙' : '打开支持墙')
                    ->requiresConfirmation()
                    ->action(function () {
                        $enabled = Setting::getInt('support_wall_enabled') === 1;
                        Setting::query()->updateOrCreate(
                            ['key' => 'support_wall_enabled'],
                            ['value' => $enabled ? '0' : '1'],
                        );
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('详情'),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => SupportOrderResource\Pages\ListSupportOrders::route('/'),
            'view' => SupportOrderResource\Pages\ViewSupportOrder::route('/{record}'),
        ];
    }

    private static function logLine(?SupportLog $log): ?string
    {
        if (! $log) {
            return null;
        }

        $paws = $log->paws > 0 ? '+'.$log->paws : (string) $log->paws;

        return $log->created_at?->format('Y-m-d H:i').' '.$paws.' '.$log->source->label().'，累计 '.$log->balance_after;
    }
}
