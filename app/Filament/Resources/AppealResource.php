<?php

namespace App\Filament\Resources;

use App\Enums\AppealStatus;
use App\Models\Appeal;
use App\Services\LeadService;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AppealResource extends Resource
{
    protected static ?string $model = Appeal::class;

    protected static ?string $navigationLabel = '申诉';

    protected static ?string $modelLabel = '申诉';

    protected static ?string $pluralModelLabel = '申诉';

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?int $navigationSort = 50;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID'),
                Tables\Columns\TextColumn::make('post_id')->label('帖子'),
                Tables\Columns\TextColumn::make('content')->label('说明')->limit(40),
                Tables\Columns\TextColumn::make('status')
                    ->label('状态')
                    ->formatStateUsing(fn (AppealStatus $state) => $state->label()),
            ])
            ->defaultSort('id', 'desc')
            ->actions([
                Tables\Actions\Action::make('release')
                    ->label('放行结清')
                    ->visible(fn (Appeal $record) => $record->status === AppealStatus::Pending)
                    ->requiresConfirmation()
                    ->action(fn (Appeal $record) => app(LeadService::class)->releaseAppeal($record)),
                Tables\Actions\Action::make('uphold')
                    ->label('申诉成立')
                    ->visible(fn (Appeal $record) => $record->status === AppealStatus::Pending)
                    ->requiresConfirmation()
                    ->action(fn (Appeal $record) => app(LeadService::class)->upholdAppeal($record)),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => AppealResource\Pages\ListAppeals::route('/'),
        ];
    }
}
