<?php

namespace Tests\Feature;

use App\Enums\CluePostStatus;
use App\Enums\PostStatus;
use App\Models\ContentCheck;
use App\Models\Notification;
use App\Models\Setting;
use App\Services\WechatMsgCrypt;
use App\Support\DebugUsers;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContentSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
        config([
            'services.wechat.mini_app_id' => 'wx-test',
            'services.wechat.mini_secret' => 'secret',
            'services.wechat.msg_token' => 'msg-token',
            'services.wechat.content_check' => true,
        ]);
        foreach (['owner' => 'openid-owner', 'clue' => 'openid-clue'] as $role => $openid) {
            $user = DebugUsers::resolve($role);
            $user->wechat_openid = $openid;
            $user->save();
        }
        Setting::query()->where('key', 'max_active_posts')->update(['value' => '5']);
        Setting::query()->where('key', 'post_review_enabled')->update(['value' => '0']);
    }

    public function test_risky_text_is_rejected_before_a_post_is_saved(): void
    {
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response(['access_token' => 'at', 'expires_in' => 7200]),
            'api.weixin.qq.com/wxa/msg_sec_check*' => Http::response([
                'errcode' => 0,
                'result' => ['suggest' => 'risky', 'label' => 20002],
            ]),
        ]);

        $this->asRole('owner')->postJson('/api/posts', $this->postPayload('违规奶糖'))
            ->assertStatus(422)
            ->assertJsonPath('message', '内容未通过安全检测，请修改后再发');

        $this->assertDatabaseMissing('posts', ['pet_name' => '违规奶糖']);
    }

    public function test_images_stay_hidden_until_wechat_passes_them(): void
    {
        $this->fakeChecks();

        $postId = $this->asRole('owner')->postJson('/api/posts', $this->postPayload('待检测'))
            ->assertOk()
            ->assertJsonPath('data.status', PostStatus::PendingReview->value)
            ->json('data.id');
        $clueId = $this->asRole('owner')->postJson('/api/clue-posts', $this->cluePayload())
            ->assertOk()
            ->assertJsonPath('data.status', CluePostStatus::PendingReview->value)
            ->json('data.id');

        $this->assertDatabaseHas('content_checks', [
            'subject_type' => ContentCheck::POST,
            'subject_id' => $postId,
            'trace_id' => 'trace-post',
            'status' => ContentCheck::PENDING,
        ]);

        $this->pushResult('trace-post', 'pass');
        $this->pushResult('trace-clue', 'pass');

        $this->asRole('owner')->getJson('/api/posts/'.$postId)
            ->assertOk()
            ->assertJsonPath('data.status', PostStatus::Showing->value);
        $this->asRole('owner')->getJson('/api/clue-posts/'.$clueId)
            ->assertOk()
            ->assertJsonPath('data.status', CluePostStatus::Showing->value);

        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response(['access_token' => 'at', 'expires_in' => 7200]),
            'api.weixin.qq.com/wxa/msg_sec_check*' => Http::response([
                'errcode' => 0,
                'result' => ['suggest' => 'pass', 'label' => 100],
            ]),
            'api.weixin.qq.com/wxa/media_check_async*' => Http::response([
                'errcode' => 0,
                'trace_id' => 'trace-lead',
            ]),
        ]);
        $leadId = $this->asRole('clue')->postJson('/api/posts/'.$postId.'/leads', $this->leadPayload())
            ->assertOk()
            ->json('data.id');
        $this->asRole('owner')->getJson('/api/my/incoming-leads')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->pushResult('trace-lead', 'pass');

        $this->asRole('owner')->getJson('/api/my/incoming-leads')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $leadId);
        $this->assertTrue(Notification::query()->where('dedupe_key', 'growth:lead:'.$leadId)->exists());
    }

    public function test_risky_image_callback_rejects_the_post(): void
    {
        $this->fakeChecks('trace-risky');
        $postId = $this->asRole('owner')->postJson('/api/posts', $this->postPayload('图片违规'))
            ->assertOk()
            ->json('data.id');

        $this->pushResult('trace-risky', 'risky', 20002);

        $this->asRole('owner')->getJson('/api/posts/'.$postId)
            ->assertOk()
            ->assertJsonPath('data.status', PostStatus::Rejected->value)
            ->assertJsonPath('data.reject_reason', '内容未通过安全检测');
        $this->assertTrue(Notification::query()->where('dedupe_key', 'security:post:'.$postId)->exists());
    }

    public function test_message_push_checks_the_signature_and_accepts_encrypted_results(): void
    {
        $this->get('/api/wechat/message?'.$this->signed(['echostr' => 'hello']))
            ->assertOk();
        $this->assertSame('hello', $this->get('/api/wechat/message?'.$this->signed(['echostr' => 'hello']))->getContent());
        $this->get('/api/wechat/message?echostr=hello&timestamp=1710000000&nonce=nonce&signature=bad')
            ->assertStatus(403);

        $aes = rtrim(base64_encode(random_bytes(32)), '=');
        config(['services.wechat.msg_aes_key' => $aes]);
        $this->fakeChecks('trace-secret');
        $postId = $this->asRole('owner')->postJson('/api/posts', $this->postPayload('加密回推'))
            ->assertOk()
            ->json('data.id');

        $cipher = app(WechatMsgCrypt::class)->encrypt(json_encode([
            'MsgType' => 'event',
            'Event' => 'wxa_media_check',
            'trace_id' => 'trace-secret',
            'errcode' => 0,
            'result' => ['suggest' => 'risky', 'label' => 20002],
        ], JSON_UNESCAPED_UNICODE));
        $this->postJson('/api/wechat/message?'.$this->signed([], $cipher), ['Encrypt' => $cipher])
            ->assertOk();

        $this->assertSame(
            PostStatus::Rejected->value,
            $this->asRole('owner')->getJson('/api/posts/'.$postId)->json('data.status'),
        );
    }

    private function fakeChecks(string $traceId = 'trace-post'): void
    {
        Http::fake([
            'api.weixin.qq.com/cgi-bin/token*' => Http::response(['access_token' => 'at', 'expires_in' => 7200]),
            'api.weixin.qq.com/wxa/msg_sec_check*' => Http::response([
                'errcode' => 0,
                'result' => ['suggest' => 'pass', 'label' => 100],
            ]),
            'api.weixin.qq.com/wxa/media_check_async*' => Http::sequence()
                ->push(['errcode' => 0, 'trace_id' => $traceId])
                ->push(['errcode' => 0, 'trace_id' => 'trace-clue'])
                ->push(['errcode' => 0, 'trace_id' => 'trace-lead']),
        ]);
    }

    private function pushResult(string $traceId, string $suggest, int $label = 100): void
    {
        $this->postJson('/api/wechat/message?'.$this->signed(), [
            'MsgType' => 'event',
            'Event' => 'wxa_media_check',
            'trace_id' => $traceId,
            'errcode' => 0,
            'result' => ['suggest' => $suggest, 'label' => $label],
        ])->assertOk();
    }

    private function signed(array $extra = [], ?string $encrypt = null): string
    {
        $timestamp = '1710000000';
        $nonce = 'nonce';
        $parts = ['msg-token', $timestamp, $nonce];
        if ($encrypt !== null) {
            $parts[] = $encrypt;
        }
        sort($parts, SORT_STRING);
        $query = array_merge([
            'timestamp' => $timestamp,
            'nonce' => $nonce,
        ], $extra);
        if ($encrypt !== null) {
            $query['encrypt_type'] = 'aes';
            $query['msg_signature'] = sha1(implode($parts));
        } else {
            $query['signature'] = sha1(implode($parts));
        }

        return http_build_query($query);
    }

    private function asRole(string $role): static
    {
        return $this->withHeader('X-Debug-User', $role);
    }

    private function upload(string $role): string
    {
        return $this->asRole($role)->post('/api/uploads', [
            'file' => UploadedFile::fake()->image('pet.jpg'),
        ])->assertOk()->json('data.path');
    }

    private function postPayload(string $name): array
    {
        return [
            'pet_name' => $name,
            'breed' => '柯基',
            'sex' => '公',
            'description' => '于小区走失',
            'pet_traits' => '喜欢追球',
            'province' => '浙江省',
            'city' => '杭州市',
            'lost_place' => '小区北门',
            'lost_story' => '出门后未归',
            'lost_at' => now()->subDay()->toDateTimeString(),
            'reward_amount' => 0,
            'contact_name' => '宠主',
            'contact_phone' => '13800000001',
            'phone' => '13800000001',
            'escrow_to_platform' => false,
            'images' => [$this->upload('owner')],
        ];
    }

    private function cluePayload(): array
    {
        return [
            'description' => '小区门口看到一只黄狗',
            'province' => '浙江省',
            'city' => '杭州市',
            'found_at' => now()->subHour()->toDateTimeString(),
            'contact_name' => '路人',
            'contact_phone' => '13800000001',
            'phone' => '13800000001',
            'contact_public' => true,
            'images' => [$this->upload('owner')],
        ];
    }

    private function leadPayload(): array
    {
        return [
            'kind' => 'held',
            'content' => '在公园看到相似的狗',
            'contact_name' => '线索人',
            'contact_phone' => '13800000002',
            'phone' => '13800000002',
            'images' => [$this->upload('clue')],
        ];
    }
}
