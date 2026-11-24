<?php

namespace Tests\Feature;

use App\Enums\EscrowStatus;
use App\Enums\LeadStatus;
use App\Enums\PostStatus;
use App\Filament\Resources\PostResource\Pages\EditPost;
use App\Filament\Resources\PostResource\Pages\ListPosts;
use App\Filament\Resources\PostResource\Pages\ViewPost;
use App\Models\EscrowOrder;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Support\DebugUsers;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class P0AcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_reward_over_cap_names_the_limit(): void
    {
        Setting::query()->where('key', 'reward_cap_fen')->update(['value' => '10000']);

        $this->asRole('owner')->postJson('/api/posts', $this->postPayload(10001, '超限'))
            ->assertStatus(422)
            ->assertJsonPath('message', '酬谢超出上限100元');
    }

    public function test_public_list_matches_area_from_city_down_to_town(): void
    {
        Setting::query()->where('key', 'max_active_posts')->update(['value' => '3']);

        $this->approve($this->createPost(0, '全市'));
        $districtId = $this->approve($this->createPost(0, '到区'));
        Post::query()->whereKey($districtId)->update(['district' => '西湖区']);
        $townId = $this->approve($this->createPost(0, '到镇'));
        Post::query()->whereKey($townId)->update([
            'district' => '西湖区',
            'town' => '留下镇',
            'created_at' => now()->subDays(3),
        ]);

        $city = ['province' => '浙江省', 'city' => '杭州市'];
        $this->asRole('clue')->getJson('/api/posts?'.http_build_query($city))
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
        $this->asRole('clue')->getJson('/api/posts?'.http_build_query($city + ['district' => '西湖区']))
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
        $this->asRole('clue')->getJson('/api/posts?'.http_build_query($city + ['district' => '西湖区', 'town' => '留下镇']))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.pet_name', '到镇')
            ->assertJsonPath('data.0.published_days', 3);
        $this->asRole('owner')->postJson('/api/posts', $this->postPayload(0, '缺区') + ['town' => '留下镇'])
            ->assertStatus(422);
    }

    public function test_lead_phone_ignores_spaces_and_country_code(): void
    {
        $postId = $this->approve($this->createPost(0, '号码'));
        $payload = $this->leadPayload('clue');
        unset($payload['contact_phone']);
        $payload['phone'] = '+86 139-0000-0002';

        $this->asRole('clue')->postJson('/api/posts/'.$postId.'/leads', $payload)
            ->assertOk()
            ->assertJsonPath('data.contact', null);

        $this->assertSame('13900000002', \App\Models\Lead::query()->first()->contact_phone);
    }

    public function test_unpaid_and_unreviewed_posts_are_not_public(): void
    {
        $postId = $this->createPost(10000);

        $this->asRole('clue')->getJson('/api/posts')->assertOk()->assertJsonPath('meta.total', 0);
        $this->asRole('clue')->getJson('/api/posts/'.$postId)->assertStatus(404);

        $this->asRole('owner')->postJson('/api/posts/'.$postId.'/pay')->assertOk();
        $this->assertSame(1, EscrowOrder::query()->count());
        $this->asRole('clue')->getJson('/api/posts')->assertJsonPath('meta.total', 0);

        $this->asRole('owner')->postJson('/api/posts/'.$postId.'/pay')
            ->assertOk()
            ->assertJsonPath('data.amount', 10000);
        $this->assertSame(1, EscrowOrder::query()->count());
    }

    public function test_zero_reward_lead_shows_contacts_without_escrow(): void
    {
        $postId = $this->approve($this->createPost(0));

        $this->assertSame(0, EscrowOrder::query()->count());

        $leadId = $this->asRole('clue')->postJson('/api/posts/'.$postId.'/leads', $this->leadPayload('clue'))
            ->assertOk()
            ->assertJsonPath('data.contact', null)
            ->json('data.id');

        $this->asRole('owner')->getJson('/api/posts/'.$postId.'/leads')
            ->assertOk()
            ->assertJsonPath('data.0.contact.phone', '13900000002')
            ->assertJsonPath('data.0.post.escrow_to_platform', false);

        $this->assertSame(0, EscrowOrder::query()->count());
        $this->assertNotEmpty($leadId);
    }

    public function test_sighting_lead_does_not_enter_reward(): void
    {
        $postId = $this->approve($this->pay($this->createPost(10000, '目击')));
        $payload = $this->leadPayload('clue');
        unset($payload['images']);
        $payload['kind'] = 'seen';
        $payload['content'] = '小区北门看见，往东跑了';

        $leadId = $this->asRole('clue')->postJson('/api/posts/'.$postId.'/leads', $payload)
            ->assertOk()
            ->assertJsonPath('data.kind', 'seen')
            ->assertJsonPath('data.kind_text', '只是看到')
            ->assertJsonPath('data.contact', null)
            ->json('data.id');

        $this->asRole('owner')->getJson('/api/posts/'.$postId.'/leads')
            ->assertOk()
            ->assertJsonPath('data.0.contact.phone', '13900000002')
            ->assertJsonPath('data.0.post.escrow_to_platform', true);

        $this->asRole('clue')->getJson('/api/leads/'.$leadId)
            ->assertOk()
            ->assertJsonPath('data.contact', null);

        $this->asRole('owner')->postJson('/api/leads/'.$leadId.'/match')
            ->assertStatus(422)
            ->assertJsonPath('message', '目击线索不能找对');
        $this->asRole('owner')->postJson('/api/leads/'.$leadId.'/confirm')
            ->assertStatus(422)->assertJsonPath('message', '目击线索不能确认收到');

        $this->asRole('owner')->postJson('/api/leads/'.$leadId.'/acknowledge')
            ->assertOk()
            ->assertJsonPath('data.status', LeadStatus::Noted->value)
            ->assertJsonPath('data.status_text', '已了解');
        $this->asRole('owner')->getJson('/api/posts/'.$postId)
            ->assertOk()
            ->assertJsonPath('data.status', PostStatus::Showing->value);
        $this->assertNull(EscrowOrder::query()->where('post_id', $postId)->first()->lead_id);

        $this->asRole('owner')->postJson('/api/leads/'.$leadId.'/reject')
            ->assertOk()
            ->assertJsonPath('data.status_text', '与本案无关');
        $this->asRole('owner')->getJson('/api/posts/'.$postId)
            ->assertJsonPath('data.status', PostStatus::Showing->value);

        $held = $this->leadPayload('clue');
        unset($held['images']);
        $this->asRole('clue')->postJson('/api/posts/'.$postId.'/leads', $held)
            ->assertStatus(422);
    }

    public function test_owner_can_only_increase_reward(): void
    {
        Setting::query()->where('key', 'reward_cap_fen')->update(['value' => '20000']);
        Setting::query()->where('key', 'max_active_posts')->update(['value' => '2']);
        $postId = $this->approve($this->pay($this->createPost(10000, '追加')));

        $this->asRole('owner')->postJson('/api/posts/'.$postId.'/reward', ['add_amount' => 0])
            ->assertStatus(422);
        $this->asRole('owner')->postJson('/api/posts/'.$postId.'/reward', ['add_amount' => 15000])
            ->assertStatus(422)
            ->assertJsonPath('message', '酬谢超出上限200元');

        $this->asRole('owner')->postJson('/api/posts/'.$postId.'/reward', ['add_amount' => 5000])
            ->assertOk()
            ->assertJsonPath('data.reward_amount', 15000)
            ->assertJsonPath('data.reward_topped_up', true);

        $order = EscrowOrder::query()->where('post_id', $postId)->first();
        $this->assertSame(15000, $order->amount);
        $this->assertSame(EscrowStatus::Held, $order->status);

        $freeId = $this->approve($this->createPost(0, '无偿追加'));
        $this->asRole('owner')->postJson('/api/posts/'.$freeId.'/reward', ['add_amount' => 2000])
            ->assertOk()
            ->assertJsonPath('data.reward_amount', 2000)
            ->assertJsonPath('data.reward_topped_up', true);
        $this->assertSame(0, EscrowOrder::query()->where('post_id', $freeId)->count());
    }

    public function test_withdraw_closes_open_leads_and_refunds_held_escrow(): void
    {
        $freeId = $this->approve($this->createPost(0, '无偿撤回'));
        $payload = $this->leadPayload('clue');
        unset($payload['images']);
        $payload['kind'] = 'seen';
        $leadId = $this->asRole('clue')->postJson('/api/posts/'.$freeId.'/leads', $payload)
            ->assertOk()
            ->json('data.id');

        $city = ['province' => '浙江省', 'city' => '杭州市'];
        $this->asRole('clue')->getJson('/api/posts?'.http_build_query($city))
            ->assertOk()
            ->assertJsonFragment(['id' => $freeId]);

        $this->asRole('owner')->postJson('/api/posts/'.$freeId.'/withdraw')->assertOk();
        $this->asRole('clue')->getJson('/api/posts?'.http_build_query($city))
            ->assertOk()
            ->assertJsonMissing(['id' => $freeId]);
        $this->assertSame(LeadStatus::ClosedWithCase, \App\Models\Lead::query()->find($leadId)->status);
        $this->asRole('owner')->getJson('/api/posts/'.$freeId)
            ->assertOk()
            ->assertJsonPath('data.status', PostStatus::Withdrawn->value)
            ->assertJsonPath('data.status_text', '已撤回');

        $paidId = $this->approve($this->pay($this->createPost(10000, '托管撤回')));
        $this->asRole('owner')->postJson('/api/posts/'.$paidId.'/withdraw')->assertOk();
        $this->assertSame(EscrowStatus::Refunded, EscrowOrder::query()->where('post_id', $paidId)->first()->status);
        $this->asRole('owner')->getJson('/api/my/posts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $paidId)
            ->assertJsonPath('data.0.status_text', '已撤回');
    }

    public function test_match_contact_window_and_refund_block_and_settlement(): void
    {
        $postId = $this->approve($this->pay($this->createPost(10000)));
        $first = $this->submitLead($postId, 'clue');
        $second = $this->submitLead($postId, 'clue');

        $this->asRole('owner')->postJson('/api/leads/'.$first.'/match')->assertOk()
            ->assertJsonPath('data.contact.phone', '13900000002');
        $this->assertDatabaseHas('notifications', [
            'user_id' => \App\Models\Lead::query()->findOrFail($first)->user_id,
            'kind' => 'handover_confirm',
            'dedupe_key' => 'handover:'.$first,
        ]);
        $this->asRole('clue')->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonFragment(['kind' => 'handover_confirm', 'title' => '请让宠主确认收到']);
        $this->asRole('owner')->postJson('/api/leads/'.$second.'/match')
            ->assertStatus(422)
            ->assertJsonPath('message', '已有找对的线索，请先处理完再找对下一条');

        $this->asRole('clue')->getJson('/api/my/leads')
            ->assertOk()
            ->assertJsonPath('data.0.contact', null);
        $this->asRole('owner')->getJson('/api/posts/'.$postId)
            ->assertOk()
            ->assertJsonMissingPath('data.owner_contact');

        $this->asRole('owner')->postJson('/api/posts/'.$postId.'/withdraw')
            ->assertStatus(422)
            ->assertJsonPath('message', '有进行中的找对，请先把该线索标为找错');

        $this->travel(49)->hours();

        $this->asRole('clue')->getJson('/api/my/leads')
            ->assertOk()
            ->assertJsonPath('data.0.contact', null);

        $this->asRole('owner')->postJson('/api/leads/'.$first.'/reject')->assertOk();
        $this->asRole('owner')->postJson('/api/leads/'.$second.'/reject')->assertOk();
        $this->asRole('owner')->postJson('/api/posts/'.$postId.'/withdraw')->assertOk();

        $this->travelBack();

        $freshFirst = $this->submitLead($this->approve($this->pay($this->createPost(20000, '豆豆'))), 'clue');
        $post = Post::query()->where('pet_name', '豆豆')->firstOrFail();
        $pending = $this->submitLead($post->id, 'clue');
        $this->asRole('owner')->postJson('/api/leads/'.$freshFirst.'/match')->assertOk();

        $this->asRole('owner')->post('/api/leads/'.$freshFirst.'/receipts', [
            'file' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertOk();
        $this->asRole('owner')->postJson('/api/leads/'.$freshFirst.'/confirm')
            ->assertOk()->assertJsonPath('data.status', PostStatus::Closed->value)
            ->assertJsonPath('data.status_text', '已结案');

        $this->asRole('owner')->getJson('/api/my/posts')
            ->assertOk()
            ->assertJsonPath('data.0.status', PostStatus::Closed->value)
            ->assertJsonPath('data.0.status_text', '已结案');

        $this->asRole('owner')->postJson('/api/posts/'.$post->id.'/appeals', [
            'content' => '还要再核对',
        ])->assertStatus(422)->assertJsonPath('message', '当前不能申诉');

        $order = $post->escrow()->first();
        $this->assertSame(EscrowStatus::PendingOffline, $order->status);
        $this->assertSame(3000, $order->commission_amount);
        $this->assertSame(17000, $order->payee_amount);

        app(\App\Services\EscrowService::class)->markSettled($order->fresh());

        $this->asRole('clue')->getJson('/api/my/leads')
            ->assertOk()
            ->assertJsonFragment(['settlement_status' => '已结清']);

        $detail = $this->asRole('owner')->getJson('/api/posts/'.$post->id)->assertOk()->json('data');
        $this->assertArrayNotHasKey('refund_balance', $detail);

        $this->assertSame(LeadStatus::ClosedWithCase, \App\Models\Lead::query()->find($pending)->status);
    }

    public function test_clue_user_can_open_post_for_poster_after_handover(): void
    {
        $postId = $this->approve($this->createPost(0, '给线索人看'));
        $leadId = $this->submitLead($postId, 'clue');
        $this->asRole('owner')->postJson('/api/leads/'.$leadId.'/match')->assertOk();

        $this->asRole('clue')->getJson('/api/posts/'.$postId)
            ->assertOk()
            ->assertJsonPath('data.pet_name', '给线索人看');

        $this->withHeader('X-Debug-User', 'ops')->getJson('/api/posts/'.$postId)
            ->assertStatus(404);
    }

    public function test_reward_kept_private_skips_escrow_and_closes_on_confirm(): void
    {
        $payload = $this->postPayload(8000, '自结');
        $payload['escrow_to_platform'] = false;

        $postId = $this->asRole('owner')->postJson('/api/posts', $payload)
            ->assertOk()
            ->assertJsonPath('data.status', PostStatus::PendingReview->value)
            ->assertJsonPath('data.escrow_held', false)
            ->assertJsonPath('data.escrow_paid', false)
            ->json('data.id');

        $this->asRole('owner')->postJson('/api/posts/'.$postId.'/pay')
            ->assertStatus(422)
            ->assertJsonPath('message', '该帖无需支付');

        $postId = $this->approve($postId);
        $this->asRole('clue')->getJson('/api/posts')
            ->assertOk()
            ->assertJsonPath('data.0.escrow_held', false);

        $leadId = $this->submitLead($postId, 'clue');
        $this->asRole('owner')->postJson('/api/leads/'.$leadId.'/match')->assertOk();
        $this->asRole('owner')->post('/api/leads/'.$leadId.'/receipts', [
            'file' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertOk();
        $this->asRole('owner')->postJson('/api/leads/'.$leadId.'/confirm')
            ->assertOk()->assertJsonPath('data.status', PostStatus::Closed->value);

        $this->assertNull(EscrowOrder::query()->where('post_id', $postId)->first());
    }

    public function test_reject_leaves_pending_review_and_refunds_escrow(): void
    {
        $plainId = $this->createPost(0, '待驳回');
        Livewire::actingAs(DebugUsers::operator())
            ->test(ListPosts::class)
            ->callTableAction('reject', $plainId, data: ['reason' => '照片不清晰'])
            ->assertHasNoTableActionErrors();

        $plain = Post::query()->findOrFail($plainId);
        $this->assertSame(PostStatus::Rejected, $plain->status);
        $this->assertSame('照片不清晰', $plain->reject_reason);
        $this->assertNull($plain->active_key);
        $this->asRole('owner')->getJson('/api/posts/'.$plainId)
            ->assertOk()
            ->assertJsonPath('data.status', PostStatus::Rejected->value)
            ->assertJsonPath('data.status_text', '已驳回')
            ->assertJsonPath('data.reject_reason', '照片不清晰');

        $paidId = $this->pay($this->createPost(5000, '托管驳回'));
        app(\App\Services\PostService::class)->reject(Post::query()->findOrFail($paidId), '信息不实');
        $this->assertSame(PostStatus::Rejected, Post::query()->findOrFail($paidId)->status);
        $this->assertSame(EscrowStatus::Refunded, EscrowOrder::query()->where('post_id', $paidId)->first()->status);
    }

    public function test_confirm_goes_straight_to_pending_offline(): void
    {
        $postId = $this->approve($this->pay($this->createPost(10000, '超时')));
        $leadId = $this->submitLead($postId, 'clue');
        $this->asRole('owner')->postJson('/api/leads/'.$leadId.'/match')->assertOk();
        $this->asRole('owner')->post('/api/leads/'.$leadId.'/receipts', [
            'file' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertOk();
        $this->asRole('owner')->postJson('/api/leads/'.$leadId.'/confirm')
            ->assertOk()->assertJsonPath('data.status', PostStatus::Closed->value)
            ->assertJsonPath('data.status_text', '已结案');

        $this->asRole('owner')->getJson('/api/my/posts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $postId)
            ->assertJsonPath('data.0.status_text', '已结案');

        $order = EscrowOrder::query()->where('post_id', $postId)->first();
        $this->assertSame(EscrowStatus::PendingOffline, $order->status);
        $this->assertSame(1500, $order->commission_amount);
        $this->assertSame(8500, $order->payee_amount);
        $this->asRole('clue')->getJson('/api/my/leads')
            ->assertJsonFragment(['settlement_status' => '待平台线下打款']);
    }

    public function test_light_expiry_reminder_counts_limit_and_blacklist(): void
    {
        $postId = $this->approve($this->pay($this->createPost(5000, '点亮')));
        $post = Post::query()->findOrFail($postId);

        $this->travelTo($post->lit_until->copy()->subHours(12));
        Artisan::call('posts:remind-expiring');
        Artisan::call('posts:remind-expiring');
        $this->asRole('owner')->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.post_id', $postId);

        $this->travelTo($post->lit_until->copy()->addMinute());
        Artisan::call('posts:freeze-expired');
        $this->asRole('clue')->getJson('/api/posts')->assertJsonPath('meta.total', 0);

        $this->asRole('owner')->postJson('/api/posts/'.$postId.'/light')->assertOk();
        $this->asRole('clue')->getJson('/api/posts')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.escrow_held', true);

        Livewire::actingAs(DebugUsers::operator())
            ->test(ViewPost::class, ['record' => $postId])
            ->assertSee('于小区走失')
            ->assertSee('柯基');

        Livewire::actingAs(DebugUsers::operator())
            ->test(EditPost::class, ['record' => $postId])
            ->fillForm([
                'view_count' => 20,
                'share_count' => 4,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->asRole('owner')->getJson('/api/posts/'.$postId)
            ->assertJsonPath('data.view_count', 20)
            ->assertJsonPath('data.share_count', 4)
            ->assertJsonPath('data.escrow_paid', true);

        $this->asRole('owner')->postJson('/api/posts', $this->postPayload(0, '第二只'))
            ->assertStatus(422);

        User::query()->where('email', DebugUsers::OWNER)->update(['is_blocked' => true]);
        Post::query()->whereKey($postId)->update([
            'status' => PostStatus::Withdrawn->value,
            'active_key' => null,
        ]);
        $this->asRole('owner')->postJson('/api/posts', $this->postPayload(0, '拉黑后'))
            ->assertStatus(403);
    }

    public function test_admin_forward_tags_show_on_the_post(): void
    {
        $postId = $this->approve($this->createPost(0, '已转发'));

        $this->asRole('clue')->getJson('/api/posts')
            ->assertOk()
            ->assertJsonPath('data.0.forwarded_wechat', false)
            ->assertJsonPath('data.0.forwarded_douyin', false)
            ->assertJsonPath('data.0.forwarded_rescue_team', false);

        Livewire::actingAs(DebugUsers::operator())
            ->test(EditPost::class, ['record' => $postId])
            ->fillForm([
                'forwarded_wechat' => true,
                'forwarded_douyin' => true,
                'forwarded_rescue_team' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->asRole('clue')->getJson('/api/posts/'.$postId)
            ->assertOk()
            ->assertJsonPath('data.forwarded_wechat', true)
            ->assertJsonPath('data.forwarded_douyin', true)
            ->assertJsonPath('data.forwarded_rescue_team', false);
    }

    public function test_not_my_pet_returns_post_to_showing(): void
    {
        $postId = $this->approve($this->pay($this->createPost(8000, '认错')));
        $leadId = $this->submitLead($postId, 'clue');
        $this->asRole('owner')->postJson('/api/leads/'.$leadId.'/match')->assertOk();
        $this->asRole('owner')->postJson('/api/leads/'.$leadId.'/not-mine')
            ->assertOk()
            ->assertJsonPath('data.status', LeadStatus::HandoverFailed->value)
            ->assertJsonPath('data.post_status', PostStatus::Showing->value);

        $this->assertSame(EscrowStatus::Held, EscrowOrder::query()->where('post_id', $postId)->first()->status);
    }

    public function test_submitter_can_open_own_lead(): void
    {
        $postId = $this->approve($this->createPost(0, '可看'));
        $leadId = $this->submitLead($postId, 'clue');

        $this->asRole('clue')->getJson('/api/leads/'.$leadId)
            ->assertOk()
            ->assertJsonPath('data.content', '在公园看到相似的狗')
            ->assertJsonPath('data.is_post_owner', false)
            ->assertJsonPath('data.post.pet_name', '可看')
            ->assertJsonPath('data.contact', null);

        $this->asRole('owner')->getJson('/api/leads/'.$leadId)
            ->assertOk()
            ->assertJsonPath('data.is_post_owner', true);

        $this->withHeader('X-Debug-User', 'ops')->getJson('/api/leads/'.$leadId)
            ->assertStatus(403)
            ->assertJsonPath('message', '无权查看线索');
    }

    public function test_owner_sees_unread_count_until_the_inbox_is_opened(): void
    {
        $postId = $this->approve($this->createPost(0, '等线索'));
        $this->asRole('owner')->getJson('/api/my/incoming-leads/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 0);

        $this->submitLead($postId, 'clue');
        $this->asRole('clue')->getJson('/api/my/incoming-leads/unread-count')
            ->assertJsonPath('data.count', 0);
        $this->asRole('owner')->getJson('/api/my/incoming-leads/unread-count')
            ->assertJsonPath('data.count', 1);

        $this->asRole('owner')->getJson('/api/my/incoming-leads')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.content', '在公园看到相似的狗');
        $this->asRole('owner')->getJson('/api/my/incoming-leads/unread-count')
            ->assertJsonPath('data.count', 0);

        $this->submitLead($postId, 'clue');
        $this->asRole('owner')->getJson('/api/my/incoming-leads/unread-count')
            ->assertJsonPath('data.count', 1);
    }

    public function test_same_post_handover_notice_is_listed_once(): void
    {
        Setting::query()->where('key', 'max_active_posts')->update(['value' => '2']);
        $postId = $this->approve($this->pay($this->createPost(10000, '奶糖')));
        $first = $this->submitLead($postId, 'clue');
        $second = $this->submitLead($postId, 'clue');
        $this->asRole('owner')->postJson('/api/leads/'.$first.'/match')->assertOk();
        $this->asRole('owner')->postJson('/api/leads/'.$first.'/reject')->assertOk();
        $this->asRole('owner')->postJson('/api/leads/'.$second.'/match')->assertOk();

        $otherId = $this->approve($this->pay($this->createPost(10000, '豆豆')));
        $third = $this->submitLead($otherId, 'clue');
        $this->asRole('owner')->postJson('/api/leads/'.$third.'/match')->assertOk();

        $this->assertSame(2, Notification::query()->where('post_id', $postId)->where('kind', 'handover_confirm')->count());
        $this->asRole('clue')->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.prompt', 2);
        $this->asRole('clue')->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonFragment(['kind' => 'handover_confirm', 'subject' => '奶糖', 'post_id' => $postId, 'unread' => true])
            ->assertJsonFragment(['kind' => 'handover_confirm', 'subject' => '豆豆', 'post_id' => $otherId, 'unread' => true]);

        $noticeId = collect($this->asRole('clue')->getJson('/api/notifications')->json('data'))
            ->firstWhere('subject', '奶糖')['id'];
        $this->asRole('clue')->postJson('/api/notifications/'.$noticeId.'/read')->assertOk();
        $this->assertSame(0, Notification::query()->where('post_id', $postId)->where('kind', 'handover_confirm')->whereNull('read_at')->count());
        $this->asRole('clue')->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.prompt', 1);
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

    private function postPayload(int $reward, string $name = '奶糖'): array
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
            'reward_amount' => $reward,
            'contact_name' => '宠主',
            'contact_phone' => '13800000001',
            'phone' => '13800000001',
            'escrow_to_platform' => $reward > 0,
            'images' => [$this->upload('owner')],
        ];
    }

    private function createPost(int $reward, string $name = '奶糖'): int
    {
        return $this->asRole('owner')->postJson('/api/posts', $this->postPayload($reward, $name))
            ->assertOk()
            ->assertJsonPath('data.sex', '公')
            ->assertJsonPath('data.published_days', 0)
            ->assertJsonPath('data.escrow_to_platform', $reward > 0)
            ->assertJsonPath('data.forwarded_wechat', false)
            ->assertJsonPath('data.forwarded_douyin', false)
            ->assertJsonPath('data.forwarded_rescue_team', false)
            ->json('data.id');
    }

    private function pay(int $postId): int
    {
        $this->asRole('owner')->postJson('/api/posts/'.$postId.'/pay')->assertOk();

        return $postId;
    }

    private function approve(int $postId): int
    {
        app(\App\Services\PostService::class)->approve(Post::query()->findOrFail($postId));

        return $postId;
    }

    private function leadPayload(string $role): array
    {
        $phone = $role === 'clue' ? '13900000002' : '13700000003';

        return [
            'kind' => 'held',
            'content' => '在公园看到相似的狗',
            'contact_name' => '线索人',
            'contact_phone' => $phone,
            'phone' => $phone,
            'images' => [$this->upload($role)],
        ];
    }

    private function submitLead(int $postId, string $role): int
    {
        return $this->asRole($role)->postJson('/api/posts/'.$postId.'/leads', $this->leadPayload($role))
            ->assertOk()
            ->json('data.id');
    }
}
