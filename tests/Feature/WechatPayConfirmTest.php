<?php

namespace Tests\Feature;

use App\Enums\SupportOrderStatus;
use App\Models\SupportOrder;
use App\Models\User;
use App\Models\WechatPayment;
use App\Services\WechatPayClient;
use App\Services\WechatPaymentGateway;
use App\Support\DebugUsers;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WechatPayConfirmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_confirm_marks_support_paid_when_wechat_says_success(): void
    {
        $this->payment();
        $this->mock(WechatPayClient::class, function ($mock) {
            $mock->shouldReceive('query')->once()->andReturn([
                'trade_state' => 'SUCCESS',
                'out_trade_no' => 'WCONFIRM0001',
                'transaction_id' => '4200000001',
                'amount' => ['total' => 100],
            ]);
        });

        $this->withHeader('X-Debug-User', 'owner')
            ->postJson('/api/pay/wechat/confirm', ['out_trade_no' => 'WCONFIRM0001'])
            ->assertOk()
            ->assertJsonPath('message', 'ok');

        $payment = WechatPayment::query()->where('out_trade_no', 'WCONFIRM0001')->firstOrFail();
        $this->assertNotNull($payment->paid_at);
        $this->assertSame('4200000001', $payment->transaction_id);
        $this->assertSame(1, DebugUsers::resolve('owner')->support_paws);
        $this->assertSame(SupportOrderStatus::Paid, SupportOrder::query()->firstOrFail()->status);
    }

    public function test_confirm_waits_when_wechat_has_not_finished(): void
    {
        $this->payment();
        $this->mock(WechatPayClient::class, function ($mock) {
            $mock->shouldReceive('query')->once()->andReturn([
                'trade_state' => 'NOTPAY',
                'out_trade_no' => 'WCONFIRM0001',
                'amount' => ['total' => 100],
            ]);
        });

        $this->withHeader('X-Debug-User', 'owner')
            ->postJson('/api/pay/wechat/confirm', ['out_trade_no' => 'WCONFIRM0001'])
            ->assertStatus(422)
            ->assertJsonPath('message', '支付结果确认中');

        $this->assertNull(WechatPayment::query()->where('out_trade_no', 'WCONFIRM0001')->value('paid_at'));
        $this->assertSame(0, DebugUsers::resolve('owner')->support_paws);
    }

    public function test_debug_user_pays_with_a_fresh_wechat_code(): void
    {
        config([
            'services.wechat.mini_app_id' => 'wx-test',
            'services.wechat.mini_secret' => 'secret',
        ]);
        Http::fake([
            'api.weixin.qq.com/*' => Http::response(['openid' => 'openid-pay']),
        ]);
        $client = \Mockery::mock(WechatPayClient::class);
        $client->shouldReceive('jsapi')->once()->withArgs(function ($no, $amount, $description, $user) {
            return $user instanceof User && $user->wechat_openid === 'openid-pay' && $amount === 100;
        })->andReturn([
            'timeStamp' => '1',
            'nonceStr' => 'n',
            'package' => 'prepay_id=prepay',
            'signType' => 'RSA',
            'paySign' => 'sign',
        ]);

        $request = Request::create('/api/support/orders', 'POST');
        $request->headers->set('X-Wechat-Code', 'fresh-code');
        $this->app->instance('request', $request);

        $order = $this->payment();
        $params = (new WechatPaymentGateway($client))->chargeSupport($order);

        $this->assertSame('prepay_id=prepay', $params['package']);
        $this->assertNotSame('', $params['out_trade_no']);
        $this->assertSame('openid-pay', User::query()->where('wechat_openid', 'openid-pay')->value('wechat_openid'));
    }

    public function test_confirm_rejects_another_users_payment(): void
    {
        $this->payment();
        $this->mock(WechatPayClient::class, function ($mock) {
            $mock->shouldReceive('query')->never();
        });

        $this->withHeader('X-Debug-User', 'clue')
            ->postJson('/api/pay/wechat/confirm', ['out_trade_no' => 'WCONFIRM0001'])
            ->assertStatus(404)
            ->assertJsonPath('message', '支付单不存在');
    }

    private function payment(): SupportOrder
    {
        $owner = DebugUsers::resolve('owner');
        $order = new SupportOrder;
        $order->user_id = $owner->id;
        $order->paw_count = 1;
        $order->amount = 100;
        $order->anonymous = false;
        $order->status = SupportOrderStatus::Pending;
        $order->out_trade_no = 'SCONFIRM0001';
        $order->client_token = 'confirm-token';
        $order->save();

        $payment = new WechatPayment;
        $payment->out_trade_no = 'WCONFIRM0001';
        $payment->amount = 100;
        $payment->kind = WechatPayment::SUPPORT;
        $payment->support_order_id = $order->id;
        $payment->save();

        return $order;
    }
}
