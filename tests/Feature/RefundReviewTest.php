<?php

namespace Tests\Feature;

use App\Enums\EscrowStatus;
use App\Enums\PostStatus;
use App\Filament\Resources\EscrowOrderResource\Pages\ListEscrowOrders;
use App\Filament\Resources\PostResource\Pages\ListPosts;
use App\Models\EscrowOrder;
use App\Models\Notification;
use App\Models\Post;
use App\Support\DebugUsers;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class RefundReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_withdraw_without_a_match_still_refunds_immediately(): void
    {
        $postId = $this->approve($this->pay($this->createPost('直接退')));
        $this->asRole('owner')->postJson('/api/posts/'.$postId.'/withdraw')
            ->assertOk()
            ->assertJsonPath('data.refund_text', '已撤回');

        $this->assertSame(EscrowStatus::Refunded, EscrowOrder::query()->where('post_id', $postId)->first()->status);

        Livewire::actingAs(DebugUsers::operator())
            ->test(ListPosts::class)
            ->assertSee('已退款');
    }

    public function test_withdraw_after_a_match_waits_for_admin(): void
    {
        [$postId, $leadId] = $this->matchedPost('审批豆');
        $this->asRole('owner')->postJson('/api/leads/'.$leadId.'/not-mine')->assertOk();
        $this->asRole('owner')->getJson('/api/posts/'.$postId)
            ->assertOk()
            ->assertJsonPath('data.refund_route', 'review');

        $this->asRole('owner')->postJson('/api/posts/'.$postId.'/withdraw')
            ->assertOk()
            ->assertJsonPath('data.refund_text', '已撤回。退款需平台确认，通过后才会原路退回。');

        $order = EscrowOrder::query()->where('post_id', $postId)->firstOrFail();
        $this->assertSame(EscrowStatus::RefundReview, $order->status);
        Livewire::actingAs(DebugUsers::operator())
            ->test(ListPosts::class)
            ->assertSee('待审批');
        $this->assertSame($leadId, $order->lead_id);
        $this->assertSame(PostStatus::Withdrawn, Post::query()->find($postId)->status);
        $this->assertNotNull(Notification::query()->where('dedupe_key', 'refund-review:'.$order->id)->first());
        $this->assertNotNull(DebugUsers::operator()->notifications()->where('data->dedupe', 'escrow-refund-review:'.$order->id)->first());

        $this->asRole('clue')->getJson('/api/my/leads')
            ->assertOk()
            ->assertJsonPath('data.0.settlement_status', '宠主申请退款，待平台处理');

        Livewire::actingAs(DebugUsers::operator())
            ->test(ListEscrowOrders::class)
            ->set('activeTab', 'refund')
            ->assertSee('审批豆')
            ->callTableAction('approveRefund', $order);

        $this->assertSame(EscrowStatus::Refunded, $order->refresh()->status);
    }

    public function test_refusing_a_refund_pays_the_clue_instead(): void
    {
        [$postId, $leadId] = $this->matchedPost('驳回豆');
        $this->asRole('owner')->postJson('/api/leads/'.$leadId.'/not-mine')->assertOk();
        $this->asRole('owner')->postJson('/api/posts/'.$postId.'/withdraw')->assertOk();
        $order = EscrowOrder::query()->where('post_id', $postId)->firstOrFail();

        Livewire::actingAs(DebugUsers::operator())
            ->test(ListEscrowOrders::class)
            ->set('activeTab', 'refund')
            ->callTableAction('refuseRefund', $order);

        $order->refresh();
        $this->assertSame(EscrowStatus::PendingOffline, $order->status);
        $this->assertSame(8500, $order->payee_amount);
        $this->assertSame(PostStatus::Closed, Post::query()->find($postId)->status);
        $this->asRole('clue')->getJson('/api/my/leads')
            ->assertJsonPath('data.0.settlement_status', '待平台线下打款');
    }

    public function test_handover_can_be_paid_to_the_clue_offline(): void
    {
        [$postId] = $this->matchedPost('交接豆');
        $order = EscrowOrder::query()->where('post_id', $postId)->firstOrFail();

        Livewire::actingAs(DebugUsers::operator())
            ->test(ListEscrowOrders::class)
            ->set('activeTab', 'handover')
            ->assertSee('交接豆')
            ->callTableAction('payClue', $order);

        $order->refresh();
        $this->assertSame(EscrowStatus::PendingOffline, $order->status);
        $this->assertSame(8500, $order->payee_amount);
        $this->assertSame(PostStatus::Closed, Post::query()->find($postId)->status);
        $this->asRole('clue')->getJson('/api/my/leads')
            ->assertJsonPath('data.0.settlement_status', '待平台线下打款');
    }

    private function matchedPost(string $name): array
    {
        $postId = $this->approve($this->pay($this->createPost($name)));
        $leadId = $this->asRole('clue')->postJson('/api/posts/'.$postId.'/leads', [
            'kind' => 'held',
            'content' => '狗在我这里',
            'contact_name' => '线索人',
            'contact_phone' => '13900000002',
            'phone' => '13900000002',
            'images' => [$this->upload('clue')],
        ])->assertOk()->json('data.id');
        $this->asRole('owner')->postJson('/api/leads/'.$leadId.'/match')->assertOk();

        return [$postId, $leadId];
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

    private function createPost(string $name): int
    {
        return $this->asRole('owner')->postJson('/api/posts', [
            'pet_name' => $name,
            'breed' => '柯基',
            'sex' => '公',
            'description' => '于小区走失',
            'pet_traits' => '喜欢追球',
            'province' => '广西壮族自治区',
            'city' => '北海市',
            'lost_place' => '小区北门',
            'lost_story' => '出门后未归',
            'lost_at' => now()->subDay()->toDateTimeString(),
            'reward_amount' => 10000,
            'contact_name' => '宠主',
            'contact_phone' => '13800000001',
            'phone' => '13800000001',
            'escrow_to_platform' => true,
            'images' => [$this->upload('owner')],
        ])->assertOk()->json('data.id');
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
}
