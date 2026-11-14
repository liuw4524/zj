<?php

namespace App\Filament\Resources;

use App\Enums\CluePostStatus;
use App\Models\CluePost;
use App\Services\CluePostService;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CluePostResource extends Resource
{
    protected static ?string $model = CluePost::class;

    protected static ?string $navigationLabel = '线索帖';

    protected static ?string $modelLabel = '线索帖';

    protected static ?string $pluralModelLabel = '线索帖';

    protected static ?string $navigationIcon = 'heroicon-o-camera';

    protected static ?int $navigationSort = 25;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('images');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\TextEntry::make('description')->label('说明')->columnSpanFull(),
            Infolists\Components\TextEntry::make('province')->label('省'),
            Infolists\Components\TextEntry::make('city')->label('市'),
            Infolists\Components\TextEntry::make('found_at')->label('发现时间')->dateTime('Y-m-d H:i'),
            Infolists\Components\TextEntry::make('status')
                ->label('状态')
                ->formatStateUsing(fn (CluePostStatus $state) => $state->label()),
            Infolists\Components\TextEntry::make('contact_public')
                ->label('联系方式公开')
                ->formatStateUsing(fn ($state) => $state ? '公开' : '不公开'),
            Infolists\Components\TextEntry::make('contact_name')->label('联系人'),
            Infolists\Components\TextEntry::make('contact_phone')->label('联系电话'),
            Infolists\Components\TextEntry::make('lit_until')->label('点亮至')->dateTime('Y-m-d H:i')->placeholder('无'),
            Infolists\Components\TextEntry::make('delisted_at')->label('下架时间')->dateTime('Y-m-d H:i')->placeholder('无'),
            Infolists\Components\TextEntry::make('reject_reason')->label('驳回原因')->placeholder('无')->columnSpanFull(),
            Infolists\Components\ImageEntry::make('image_paths')
                ->label('图片')
                ->disk('public')
                ->height(160)
                ->getStateUsing(fn (CluePost $record) => $record->images->pluck('path')->all())
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordUrl(fn (CluePost $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID'),
                Tables\Columns\ImageColumn::make('cover')
                    ->label('图片')
                    ->getStateUsing(fn (CluePost $record) => $record->images->first()?->path)
                    ->disk('public')
                    ->height(56),
                Tables\Columns\TextColumn::make('description')->label('说明')->limit(24)->searchable(),
                Tables\Columns\TextColumn::make('city')->label('城市'),
                Tables\Columns\TextColumn::make('status')
                    ->label('状态')
                    ->formatStateUsing(fn (CluePostStatus $state) => $state->label()),
                Tables\Columns\TextColumn::make('delisted_at')->label('下架时间')->dateTime('Y-m-d')->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('状态')->options(CluePostStatus::options()),
                Tables\Filters\Filter::make('expiring')
                    ->label('即将因 30 天未点亮删除')
                    ->query(fn (Builder $query) => $query
                        ->where('status', CluePostStatus::Delisted)
                        ->whereNotNull('delisted_at')
                        ->where('delisted_at', '<=', now()->subDays(CluePostService::PURGE_DAYS - 7))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('详情'),
                Tables\Actions\Action::make('approve')
                    ->label('通过')
                    ->visible(fn (CluePost $record) => $record->status === CluePostStatus::PendingReview)
                    ->action(fn (CluePost $record) => app(CluePostService::class)->approve($record)),
                Tables\Actions\Action::make('reject')
                    ->label('驳回')
                    ->visible(fn (CluePost $record) => $record->status === CluePostStatus::PendingReview)
                    ->form([
                        Forms\Components\Textarea::make('reason')->label('原因')->required()->maxLength(200),
                    ])
                    ->action(fn (CluePost $record, array $data) => app(CluePostService::class)->reject($record, $data['reason'])),
                Tables\Actions\Action::make('delist')
                    ->label('下架')
                    ->requiresConfirmation()
                    ->visible(fn (CluePost $record) => $record->status === CluePostStatus::Showing)
                    ->action(fn (CluePost $record) => app(CluePostService::class)->delist($record)),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => CluePostResource\Pages\ListCluePosts::route('/'),
            'view' => CluePostResource\Pages\ViewCluePost::route('/{record}'),
        ];
    }
}
