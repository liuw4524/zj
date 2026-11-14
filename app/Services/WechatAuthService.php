<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WechatAuthService
{
    public function login(string $code): array
    {
        $openid = $this->openid($code);
        $user = User::query()->where('wechat_openid', $openid)->first();
        if (! $user) {
            $user = new User;
            $user->wechat_openid = $openid;
            $user->name = '微信用户';
            $user->email = 'wx_'.$openid.'@users.zj.test';
            $user->password = Str::password();
            $user->save();
            $user->refresh();
        }

        $user->tokens()->where('name', 'mini')->delete();
        $token = $user->createToken('mini')->plainTextToken;

        return [
            'token' => $token,
            'user' => $user,
        ];
    }

    private function openid(string $code): string
    {
        $appId = (string) config('services.wechat.mini_app_id');
        $secret = (string) config('services.wechat.mini_secret');
        if ($appId === '' || $secret === '') {
            throw new BusinessException('暂时无法登录');
        }

        $response = Http::timeout(5)->get('https://api.weixin.qq.com/sns/jscode2session', [
            'appid' => $appId,
            'secret' => $secret,
            'js_code' => $code,
            'grant_type' => 'authorization_code',
        ]);
        $openid = trim((string) $response->json('openid'));
        if (! $response->ok() || $openid === '') {
            throw new BusinessException('微信登录失败');
        }

        return $openid;
    }
}
