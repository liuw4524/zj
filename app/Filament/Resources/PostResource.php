<?php

namespace App\Filament\Resources;

use App\Enums\EscrowStatus;
use App\Enums\PostStatus;
use App\Exceptions\BusinessException;
use App\Models\Post;
use App\Services\PostService;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $navigationLabel = '丢失帖';

    protected static ?string $modelLabel = '丢失帖';

    protected static ?string $pluralModelLabel = '丢失帖';

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?int $navigationSort = 20;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['images', 'escrow']);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\TextEntry::make('trace_code')->label('爪迹编号')->placeholder('未生成'),
            Infolists\Components\TextEntry::make('pet_name')->label('昵称'),
            Infolists\Components\TextEntry::make('breed')->label('品种')->placeholder('未填'),
            Infolists\Components\TextEntry::make('sex')->label('性别')->placeholder('未填'),
            Infolists\Components\TextEntry::make('province')->label('省')->placeholder('未填'),
            Infolists\Components\TextEntry::make('city')->label('市'),
            Infolists\Components\TextEntry::make('district')->label('区/县')->placeholder('未填'),
            Infolists\Components\TextEntry::make('town')->label('镇/乡')->placeholder('未填'),
            Infolists\Components\TextEntry::make('lost_place')->label('具体丢失地点')->placeholder('未填'),
            Infolists\Components\TextEntry::make('lost_at')->label('丢失时间')->dateTime('Y-m-d H:i'),
            Infolists\Components\TextEntry::make('reward_amount')
                ->label('酬谢')
                ->formatStateUsing(fn ($state) => Money::yuan($state)),
            Infolists\Components\TextEntry::make('reward_topped_up')
                ->label('已追加酬谢')
                ->formatStateUsing(fn ($state) => $state ? '是' : '否'),
            Infolists\Components\TextEntry::make('escrow_to_platform')
                ->label('交给平台')
                ->formatStateUsing(fn ($state) => $state ? '是' : '否'),
            Infolists\Components\TextEntry::make('status')
                ->label('状态')
                ->formatStateUsing(fn (PostStatus $state) => $state->label()),
            Infolists\Components\TextEntry::make('refunded')
                ->label('退款')
                ->visible(fn (Post $record) => $record->status === PostStatus::Withdrawn)
                ->state(fn (Post $record) => static::refundLabel($record)),
            Infolists\Components\TextEntry::make('contact_name')->label('联系人'),
            Infolists\Components\TextEntry::make('contact_phone')->label('联系电话'),
            Infolists\Components\TextEntry::make('description')->label('宠物描述')->columnSpanFull(),
            Infolists\Components\TextEntry::make('pet_traits')->label('宠物爱好特点')->placeholder('未填')->columnSpanFull(),
            Infolists\Components\TextEntry::make('lost_story')->label('走失经过')->placeholder('未填')->columnSpanFull(),
            Infolists\Components\TextEntry::make('reject_reason')->label('驳回原因')->placeholder('无')->columnSpanFull(),
            Infolists\Components\TextEntry::make('images_purged_at')
                ->label('图片')
                ->visible(fn (Post $record) => $record->images_purged_at !== null)
                ->formatStateUsing(fn () => '已过期清理')
                ->columnSpanFull(),
            Infolists\Components\ImageEntry::make('image_paths')
                ->label('图片')
                ->visible(fn (Post $record) => $record->images_purged_at === null)
                ->disk('public')
                ->height(160)
                ->getStateUsing(fn (Post $record) => $record->images->pluck('path')->all())
                ->columnSpanFull(),
            Infolists\Components\TextEntry::make('view_count')->label('浏览'),
            Infolists\Components\TextEntry::make('share_count')->label('转发'),
            Infolists\Components\IconEntry::make('forwarded_wechat')->label('已转发微信群')->boolean(),
            Infolists\Components\IconEntry::make('forwarded_douyin')->label('已转发抖音')->boolean(),
            Infolists\Components\IconEntry::make('forwarded_rescue_team')->label('已转发寻宠队')->boolean(),
        ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('view_count')
                ->label('浏览数')
                ->numeric()
                ->required()
                ->minValue(0),
            Forms\Components\TextInput::make('share_count')
                ->label('转发数')
                ->numeric()
                ->required()
                ->minValue(0),
            Forms\Components\Toggle::make('forwarded_wechat')->label('已转发微信群'),
            Forms\Components\Toggle::make('forwarded_douyin')->label('已转发抖音'),
            Forms\Components\Toggle::make('forwarded_rescue_team')->label('已转发寻宠队'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordUrl(fn (Post $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID'),
                Tables\Columns\TextColumn::make('trace_code')
                    ->label('爪迹编号')
                    ->placeholder('未生成')
                    ->searchable(),
                Tables\Columns\ImageColumn::make('cover')
                    ->label('图片')
                    ->getStateUsing(fn (Post $record) => $record->images->first()?->path)
                    ->disk('public')
                    ->height(56),
                Tables\Columns\TextColumn::make('pet_name')->label('昵称')->searchable(),
                Tables\Columns\TextColumn::make('description')->label('内容')->limit(24)->searchable(),
                Tables\Columns\TextColumn::make('city')->label('城市'),
                Tables\Columns\TextColumn::make('reward_amount')
                    ->label('酬谢')
                    ->formatStateUsing(fn ($state) => Money::yuan($state)),
                Tables\Columns\TextColumn::make('status')
                    ->label('状态')
                    ->formatStateUsing(fn (PostStatus $state) => $state->label()),
                Tables\Columns\TextColumn::make('refunded')
                    ->label('退款')
                    ->state(fn (Post $record) => static::refundLabel($record))
                    ->placeholder('—')
                    ->color(fn (?string $state) => match ($state) {
                        '已退款' => 'success',
                        '待审批', '未退款', '退款中' => 'warning',
                        default => null,
                    }),
                Tables\Columns\TextColumn::make('view_count')->label('浏览'),
                Tables\Columns\TextColumn::make('share_count')->label('转发'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('状态')->options(PostStatus::options()),
                Tables\Filters\SelectFilter::make('city')->label('城市')->options(
                    fn () => Post::query()->distinct()->orderBy('city')->pluck('city', 'city')->all()
                ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('详情'),
                Tables\Actions\EditAction::make()->label('编辑'),
                Tables\Actions\Action::make('approve')
                    ->label('通过')
                    ->visible(fn (Post $record) => $record->status === PostStatus::PendingReview)
                    ->action(fn (Post $record) => app(PostService::class)->approve($record)),
                Tables\Actions\Action::make('reject')
                    ->label('驳回')
                    ->visible(fn (Post $record) => $record->status === PostStatus::PendingReview)
                    ->form([
                        Forms\Components\Textarea::make('reason')->label('原因')->required()->maxLength(200),
                    ])
                    ->action(fn (Post $record, array $data) => app(PostService::class)->reject($record, $data['reason'])),
                Tables\Actions\Action::make('forceClose')
                    ->label('强制结案')
                    ->requiresConfirmation()
                    ->action(fn (Post $record) => self::run(fn () => app(PostService::class)->forceClose($record))),
                Tables\Actions\Action::make('assistRefund')
                    ->label('协助退款')
                    ->requiresConfirmation()
                    ->action(fn (Post $record) => self::run(fn () => app(PostService::class)->assistRefund($record))),
            ])
            ->bulkActions([]);
    }

    private static function run(callable $action): void
    {
        try {
            $action();
        } catch (BusinessException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }
    }

    private static function refundLabel(Post $post): ?string
    {
        if ($post->status !== PostStatus::Withdrawn) {
            return null;
        }

        $order = $post->escrow;
        if ($order === null || $order->paid_at === null) {
            return '无需退款';
        }

        return match ($order->status) {
            EscrowStatus::Refunded => '已退款',
            EscrowStatus::RefundReview => '待审批',
            EscrowStatus::Refunding => '退款中',
            default => '未退款',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => PostResource\Pages\ListPosts::route('/'),
            'view' => PostResource\Pages\ViewPost::route('/{record}'),
            'edit' => PostResource\Pages\EditPost::route('/{record}/edit'),
        ];
    }
}
