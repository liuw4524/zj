<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WechatPayNotifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_notify_rejects_a_request_without_a_wechat_signature(): void
    {
        $this->postJson('/api/pay/wechat/notify', ['id' => 'not-from-wechat'])
            ->assertStatus(500)
            ->assertJsonPath('code', 'FAIL');
    }
}
