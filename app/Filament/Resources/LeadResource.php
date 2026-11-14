<?php

namespace App\Filament\Resources;

use App\Enums\LeadKind;
use App\Enums\LeadStatus;
use App\Models\Lead;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class LeadResource extends Resource
{
    protected static ?string $model = Lead::class;

    protected static ?string $navigationLabel = '线索';

    protected static ?string $modelLabel = '线索';

    protected static ?string $pluralModelLabel = '线索';

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?int $navigationSort = 30;

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
                Tables\Columns\TextColumn::make('post.pet_name')->label('宠物'),
                Tables\Columns\TextColumn::make('kind')
                    ->label('类型')
                    ->formatStateUsing(fn (LeadKind $state) => $state->label()),
                Tables\Columns\TextColumn::make('status')
                    ->label('状态')
                    ->formatStateUsing(fn (LeadStatus $state, Lead $record) => $record->statusLabel()),
                Tables\Columns\TextColumn::make('created_at')->label('提交时间')->dateTime('Y-m-d H:i'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('状态')->options(LeadStatus::options()),
                Tables\Filters\Filter::make('post_id')
                    ->form([
                        \Filament\Forms\Components\TextInput::make('post_id')->label('帖子 ID')->numeric(),
                    ])
                    ->query(function ($query, array $data) {
                        return $query->when($data['post_id'] ?? null, fn ($query, $id) => $query->where('post_id', $id));
                    }),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => LeadResource\Pages\ListLeads::route('/'),
        ];
    }
}
