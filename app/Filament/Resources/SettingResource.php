<?php

namespace App\Filament\Resources;

use App\Models\Setting;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SettingResource extends Resource
{
    protected static ?string $model = Setting::class;

    protected static ?string $navigationLabel = '平台参数';

    protected static ?string $modelLabel = '平台参数';

    protected static ?string $pluralModelLabel = '平台参数';

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = 90;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery()
            ->where('key', '!=', 'cooling_days');

        $images = array_keys(Setting::imageKeys());
        if ($images !== []) {
            $placeholders = implode(', ', array_fill(0, count($images), '?'));
            $key = $query->getQuery()->getGrammar()->wrap($query->getModel()->qualifyColumn('key'));
            $query->orderByRaw("CASE WHEN {$key} IN ({$placeholders}) THEN 0 ELSE 1 END", $images);
        }

        return $query->orderBy('id');
    }

    public static function form(Form $form): Form
    {
        return $form->schema(fn (?Setting $record): array => [
            Forms\Components\Placeholder::make('label')
                ->label('参数')
                ->content(Setting::labels()[$record?->key] ?? $record?->key),
            ...match (true) {
                array_key_exists($record?->key ?? '', Setting::imageKeys()) => [
                    Forms\Components\FileUpload::make('value')
                        ->label('图片')
                        ->image()
                        ->disk('public')
                        ->directory(Setting::imageKeys()[$record->key])
                        ->maxSize(5120)
                        ->helperText(match ($record->key) {
                            'rescue_team_image' => '展示在「我的 - 加入寻宠队」。可上传微信二维码。不上传时小程序显示暂无图片。',
                            'contact_platform_image' => '展示在「我的 - 联系平台」。可上传微信二维码。不上传时小程序显示暂无图片。',
                            default => '',
                        }),
                ],
                $record?->key === 'post_review_enabled' => [
                    Forms\Components\Select::make('value')
                        ->label('发帖审核')
                        ->required()
                        ->options([
                            '1' => '开，发帖需审核',
                            '0' => '关，发帖即展示',
                        ])
                        ->native(false)
                        ->helperText('关闭后，新的丢失帖和公益线索直接展示。托管帖仍需先完成支付。已经待审核的帖子不会自动通过。'),
                ],
                $record?->key === 'reward_cap_fen' => [
                    Forms\Components\TextInput::make('value')
                        ->label('值')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(1000000)
                        ->step(0.01)
                        ->helperText('按元填写，最多两位小数。')
                        ->regex('/^\d+(\.\d{1,2})?$/')
                        ->validationMessages([
                            'regex' => '最多两位小数',
                        ]),
                ],
                default => [
                    Forms\Components\TextInput::make('value')
                        ->label('值')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100000000)
                        ->step(1),
                ],
            },
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('key')
                    ->label('参数')
                    ->formatStateUsing(fn (string $state) => Setting::labels()[$state] ?? $state),
                Tables\Columns\TextColumn::make('value')
                    ->label('值')
                    ->placeholder(fn (Setting $record) => array_key_exists($record->key, Setting::imageKeys()) ? '未上传' : null)
                    ->formatStateUsing(function (string $state, Setting $record) {
                        if (array_key_exists($record->key, Setting::imageKeys())) {
                            return $state !== '' ? '已上传' : '未上传';
                        }
                        if ($record->key === 'reward_cap_fen') {
                            return Money::yuan($state);
                        }
                        if ($record->key !== 'post_review_enabled') {
                            return $state;
                        }

                        return $state === '1' ? '开，需审核' : '关，发帖即展示';
                    }),
                Tables\Columns\TextColumn::make('updated_at')->label('更新时间')->dateTime('Y-m-d H:i'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label(fn (Setting $record) => array_key_exists($record->key, Setting::imageKeys()) ? '上传图片' : '编辑'),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => SettingResource\Pages\ListSettings::route('/'),
            'edit' => SettingResource\Pages\EditSetting::route('/{record}/edit'),
        ];
    }
}
