<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('createAdmin')
                ->label('新增超级管理员')
                ->form([
                    Forms\Components\TextInput::make('name')
                        ->label('姓名')
                        ->default('超级管理员')
                        ->required()
                        ->maxLength(50),
                    Forms\Components\TextInput::make('email')
                        ->label('邮箱')
                        ->email()
                        ->required()
                        ->helperText('邮箱已存在时，会把该用户设为超级管理员并更新密码。'),
                    Forms\Components\TextInput::make('password')
                        ->label('密码')
                        ->password()
                        ->revealable()
                        ->required()
                        ->minLength(8),
                ])
                ->action(function (array $data) {
                    User::saveAdmin($data['name'], $data['email'], $data['password']);
                    Notification::make()->title('超级管理员已保存')->success()->send();
                }),
        ];
    }
}
