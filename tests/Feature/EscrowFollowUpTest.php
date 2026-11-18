<?php

namespace Tests\Feature;

use App\Filament\Resources\EscrowOrderResource;
use App\Filament\Resources\EscrowOrderResource\Pages\ListEscrowOrders;
use App\Models\EscrowOrder;
use App\Support\DebugUsers;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

class EscrowFollowUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_review_payout_and_withdraw_each_alert_once(): void
    {
        $postId = $this->pay($this->createPost(10000, '托管豆'));
        $order = EscrowOrder::query()->where('post_id', $postId)->firstOrFail();

        $this->assertAlert('escrow-review:'.$order->id, '新托管待审核');
        $this->assertSame('1', EscrowOrderResource::getNavigationBadge());

        Livewire::actingAs(DebugUsers::operator())
            ->test(ListEscrowOrders::class)
            ->set('activeTab', 'review')
            ->assertSee('托管豆')
            ->assertSee('100.00 元');

        $this->approve($postId);
        $leadId = $this->submitLead($postId);
        $this->asRole('owner')->postJson('/api/leads/'.$leadId.'/match')->assertOk();
        $this->asRole('owner')->post('/api/leads/'.$leadId.'/receipts', [
            'file' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertOk();
        $this->asRole('owner')->postJson('/api/leads/'.$leadId.'/confirm')->assertOk();

        $order->refresh();
        $this->assertNotNull($order->payout_ready_at);
        $this->assertAlert('escrow-payout:'.$order->id, '待打款');
        $this->assertSame(1, $this->alertCount('escrow-payout:'.$order->id));

        Livewire::actingAs(DebugUsers::operator())
            ->test(ListEscrowOrders::class)
            ->assertSee('托管豆')
            ->assertSee('85.00 元')
            ->callTableAction('settle', $order, data: ['payout_note' => '已微信转账']);

        $this->assertSame('已微信转账', $order->refresh()->payout_note);
        $this->assertSame('0', (string) EscrowOrderResource::actionableCount());

        $refundPost = $this->approve($this->pay($this->createPost(2000, '退款豆')));
        $this->asRole('owner')->postJson('/api/posts/'.$refundPost.'/withdraw')->assertOk();
        $refundOrder = EscrowOrder::query()->where('post_id', $refundPost)->firstOrFail();
        $this->assertAlert('escrow-refund:'.$refundOrder->id, '撤单退款');
        $this->assertSame(1, $this->alertCount('escrow-refund:'.$refundOrder->id));
    }

    public function test_reminders_repeat_only_at_one_and_three_days(): void
    {
        $postId = $this->approve($this->pay($this->createPost(10000, '催款豆')));
        $leadId = $this->submitLead($postId);
        $this->asRole('owner')->postJson('/api/leads/'.$leadId.'/match')->assertOk();

        $this->travel(49)->hours();
        Artisan::call('escrows:remind');
        $this->assertAlert('escrow-handover:'.$leadId, '交接未确认');
        Artisan::call('escrows:remind');
        $this->assertSame(1, $this->alertCount('escrow-handover:'.$leadId));

        $this->asRole('owner')->post('/api/leads/'.$leadId.'/receipts', [
            'file' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertOk();
        $this->asRole('owner')->postJson('/api/leads/'.$leadId.'/confirm')->assertOk();
        $order = EscrowOrder::query()->where('post_id', $postId)->firstOrFail();

        $this->travel(1)->days();
        Artisan::call('escrows:remind');
        $this->assertAlert('escrow-payout-1d:'.$order->id, '打款已等待 1 天');
        $this->assertSame(0, $this->alertCount('escrow-payout-3d:'.$order->id));

        $this->travel(2)->days();
        Artisan::call('escrows:remind');
        $third = DebugUsers::operator()->notifications()->where('data->dedupe', 'escrow-payout-3d:'.$order->id)->first();
        $this->assertNotNull($third);
        $this->assertStringStartsWith('打款已等待', $third->data['title']);
        $this->assertSame(1, $this->alertCount('escrow-payout-1d:'.$order->id));
    }

    private function assertAlert(string $dedupe, string $title): void
    {
        $alert = DebugUsers::operator()->notifications()->where('data->dedupe', $dedupe)->first();
        $this->assertNotNull($alert);
        $this->assertSame($title, $alert->data['title']);
    }

    private function alertCount(string $dedupe): int
    {
        return DebugUsers::operator()->notifications()->where('data->dedupe', $dedupe)->count();
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

    private function createPost(int $reward, string $name): int
    {
        return $this->asRole('owner')->postJson('/api/posts', [
            'pet_name' => $name,
            'breed' => '柯基',
            'sex' => '公',
            'description' => '于小区走失',
            'pet_traits' => '喜欢追球',
            'province' => '广西壮族自治区',
            'lost_place' => '小区北门',
            'lost_story' => '出门后未归',
            'city' => '北海市',
            'lost_at' => now()->subDay()->toDateTimeString(),
            'reward_amount' => $reward,
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
        app(\App\Services\PostService::class)->approve(\App\Models\Post::query()->findOrFail($postId));

        return $postId;
    }

    private function submitLead(int $postId): int
    {
        return $this->asRole('clue')->postJson('/api/posts/'.$postId.'/leads', [
            'kind' => 'held',
            'content' => '狗在我这里',
            'contact_name' => '线索人',
            'contact_phone' => '13900000002',
            'phone' => '13900000002',
            'images' => [$this->upload('clue')],
        ])->assertOk()->json('data.id');
    }
}
