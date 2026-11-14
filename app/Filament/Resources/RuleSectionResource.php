<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RuleSectionResource\Pages;
use App\Models\RuleSection;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RuleSectionResource extends Resource
{
    protected static ?string $model = RuleSection::class;

    protected static ?string $navigationLabel = '规则说明';

    protected static ?string $modelLabel = '规则';

    protected static ?string $pluralModelLabel = '规则说明';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 80;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->label('标题')
                ->required()
                ->maxLength(40),
            Forms\Components\Textarea::make('body')
                ->label('内容')
                ->required()
                ->maxLength(2000)
                ->rows(6)
                ->columnSpanFull(),
            Forms\Components\TextInput::make('sort')
                ->label('排序')
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->required()
                ->helperText('数字小的排在前面。'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort')->label('排序')->sortable(),
                Tables\Columns\TextColumn::make('title')->label('标题')->searchable(),
                Tables\Columns\TextColumn::make('body')->label('内容')->limit(40),
                Tables\Columns\TextColumn::make('updated_at')->label('更新时间')->dateTime('Y-m-d H:i'),
            ])
            ->defaultSort('sort')
            ->actions([
                Tables\Actions\EditAction::make()->label('修改'),
                Tables\Actions\DeleteAction::make()->label('删除'),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRuleSections::route('/'),
            'create' => Pages\CreateRuleSection::route('/create'),
            'edit' => Pages\EditRuleSection::route('/{record}/edit'),
        ];
    }
}
