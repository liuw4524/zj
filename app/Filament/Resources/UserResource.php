<?php

namespace App\Filament\Resources;

use App\Enums\MedalLevel;
use App\Models\GrowthLog;
use App\Models\SupportLog;
use App\Models\User;
use App\Services\GrowthService;
use App\Services\SupportService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationLabel = '用户';

    protected static ?string $modelLabel = '用户';

    protected static ?string $pluralModelLabel = '用户';

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 10;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('姓名')->searchable(),
                Tables\Columns\TextColumn::make('phone')->label('手机号'),
                Tables\Columns\TextColumn::make('growth_points')->label('成长值'),
                Tables\Columns\TextColumn::make('support_paws')->label('累计爪印'),
                Tables\Columns\TextColumn::make('medal_level')
                    ->label('爪勋章')
                    ->formatStateUsing(function ($state) {
                        $level = MedalLevel::from((int) $state);

                        return $level->label().' · '.$level->title();
                    }),
                Tables\Columns\IconColumn::make('is_admin')->label('超级管理员')->boolean(),
                Tables\Columns\IconColumn::make('is_blocked')->label('拉黑')->boolean(),
            ])
            ->actions([
                Tables\Actions\Action::make('adjustGrowth')
                    ->label('调整成长值')
                    ->form([
                        Forms\Components\TextInput::make('delta')
                            ->label('增减')
                            ->helperText('正数增加，负数减少。等级只升不降。')
                            ->required()
                            ->integer()
                            ->minValue(-100000)
                            ->maxValue(100000)
                            ->notIn([0]),
                        Forms\Components\Textarea::make('note')
                            ->label('原因')
                            ->required()
                            ->maxLength(200),
                    ])
                    ->action(function (User $record, array $data) {
                        /** @var User $operator */
                        $operator = auth()->user();
                        app(GrowthService::class)->adjust(
                            $record,
                            (int) $data['delta'],
                            (string) $data['note'],
                            $operator,
                        );
                    }),
                Tables\Actions\Action::make('growthLogs')
                    ->label('成长记录')
                    ->modalHeading('成长记录')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('关闭')
                    ->modalContent(function (User $record) {
                        $logs = $record->growthLogs()->latest('id')->limit(20)->get();
                        if ($logs->isEmpty()) {
                            return new HtmlString('<p>暂无记录</p>');
                        }

                        $rows = $logs->map(function (GrowthLog $log) {
                            $points = $log->points > 0 ? '+'.$log->points : (string) $log->points;
                            $note = $log->note ? ' '.e($log->note) : '';

                            return '<li>'.e($log->created_at?->format('Y-m-d H:i')).' '.$points.' '.e($log->source->label()).$note.'</li>';
                        })->implode('');

                        return new HtmlString('<ul>'.$rows.'</ul>');
                    }),
                Tables\Actions\Action::make('adjustSupport')
                    ->label('纠错爪印')
                    ->form([
                        Forms\Components\TextInput::make('delta')
                            ->label('增减')
                            ->helperText('正数增加，负数减少。只改累计爪印，不改公益成长值和爪勋章。')
                            ->required()
                            ->integer()
                            ->minValue(-1000000)
                            ->maxValue(1000000)
                            ->notIn([0]),
                        Forms\Components\Textarea::make('note')
                            ->label('原因')
                            ->required()
                            ->maxLength(200),
                    ])
                    ->action(function (User $record, array $data) {
                        /** @var User $operator */
                        $operator = auth()->user();
                        app(SupportService::class)->adjust(
                            $record,
                            (int) $data['delta'],
                            (string) $data['note'],
                            $operator,
                        );
                    }),
                Tables\Actions\Action::make('supportLogs')
                    ->label('爪印记录')
                    ->modalHeading('爪印记录')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('关闭')
                    ->modalContent(function (User $record) {
                        $logs = $record->supportLogs()->latest('id')->limit(20)->get();
                        if ($logs->isEmpty()) {
                            return new HtmlString('<p>暂无记录</p>');
                        }

                        $rows = $logs->map(function (SupportLog $log) {
                            $paws = $log->paws > 0 ? '+'.$log->paws : (string) $log->paws;
                            $note = $log->note ? ' '.e($log->note) : '';

                            return '<li>'.e($log->created_at?->format('Y-m-d H:i')).' '.$paws.' '.e($log->source->label()).$note.'</li>';
                        })->implode('');

                        return new HtmlString('<ul>'.$rows.'</ul>');
                    }),
                Tables\Actions\Action::make('revokeAdmin')
                    ->label('取消超级管理员')
                    ->visible(fn (User $record) => $record->is_admin)
                    ->requiresConfirmation()
                    ->action(function (User $record) {
                        if (User::query()->where('is_admin', true)->count() <= 1) {
                            Notification::make()->title('至少保留一名超级管理员')->danger()->send();

                            return;
                        }

                        $record->is_admin = false;
                        $record->save();
                    }),
                Tables\Actions\Action::make('block')
                    ->label('拉黑')
                    ->visible(fn (User $record) => ! $record->is_blocked)
                    ->requiresConfirmation()
                    ->action(function (User $record) {
                        $record->is_blocked = true;
                        $record->save();
                    }),
                Tables\Actions\Action::make('unblock')
                    ->label('解黑')
                    ->visible(fn (User $record) => $record->is_blocked)
                    ->action(function (User $record) {
                        $record->is_blocked = false;
                        $record->save();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => UserResource\Pages\ListUsers::route('/'),
        ];
    }
}
