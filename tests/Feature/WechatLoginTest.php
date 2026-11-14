<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DebugUsers;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WechatLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_wechat_login_issues_a_token_and_ignores_the_debug_header(): void
    {
        config([
            'services.wechat.mini_app_id' => 'wx-test',
            'services.wechat.mini_secret' => 'secret',
        ]);
        Http::fake([
            'api.weixin.qq.com/*' => Http::response(['openid' => 'openid-1', 'session_key' => 'hidden']),
        ]);

        $first = $this->postJson('/api/auth/wechat', ['code' => 'code-1'])
            ->assertOk()
            ->assertJsonPath('data.user.name', '微信用户')
            ->assertJsonPath('data.user.debug_role', 'owner');
        $this->assertNotEmpty($first->json('data.token'));
        $this->assertSame(1, User::query()->where('wechat_openid', 'openid-1')->count());

        $token = $this->postJson('/api/auth/wechat', ['code' => 'code-2'])
            ->assertOk()
            ->json('data.token');
        $this->assertSame(1, User::query()->where('wechat_openid', 'openid-1')->count());

        config(['app.auth_disabled' => false]);
        $this->app['auth']->forgetGuards();
        $this->withHeader('X-Debug-User', 'clue')
            ->withToken($token)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.name', '微信用户')
            ->assertJsonPath('data.debug_role', null);

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withHeader('X-Debug-User', 'clue')
            ->getJson('/api/me')
            ->assertStatus(401);

        $this->assertNotSame(DebugUsers::CLUE, User::query()->where('wechat_openid', 'openid-1')->value('email'));
    }

    public function test_wechat_login_fails_without_openid(): void
    {
        config([
            'services.wechat.mini_app_id' => 'wx-test',
            'services.wechat.mini_secret' => 'secret',
        ]);
        Http::fake([
            'api.weixin.qq.com/*' => Http::response(['errcode' => 40029, 'errmsg' => 'invalid code']),
        ]);

        $this->postJson('/api/auth/wechat', ['code' => 'bad'])
            ->assertStatus(422)
            ->assertJsonPath('message', '微信登录失败');
    }
}
