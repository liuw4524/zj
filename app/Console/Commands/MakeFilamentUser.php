<?php

namespace App\Console\Commands;

use App\Models\User;
use Filament\Commands\MakeUserCommand;
use Filament\Facades\Filament;

class MakeFilamentUser extends MakeUserCommand
{
    protected $description = '创建或更新可登录后台的超级管理员';

    protected $signature = 'make:filament-user
                            {--name= : 姓名，默认「超级管理员」}
                            {--email= : 登录邮箱}
                            {--password= : 密码，至少 8 位}';

    protected $aliases = ['filament:user'];

    public function handle(): int
    {
        if (! Filament::getCurrentPanel()) {
            $this->error('Filament 尚未安装');

            return self::INVALID;
        }

        $name = trim((string) ($this->option('name') ?: '超级管理员'));
        $email = trim((string) $this->option('email'));
        $password = (string) $this->option('password');

        if ($email === '' || $password === '') {
            $this->error('请传入 --email 和 --password');

            return self::INVALID;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('邮箱格式不正确');

            return self::INVALID;
        }

        if (strlen($password) < 8) {
            $this->error('密码至少 8 位');

            return self::INVALID;
        }

        User::saveAdmin($name, $email, $password);
        $this->info("超级管理员 {$email} 已保存，可登录 /admin");

        return self::SUCCESS;
    }
}
