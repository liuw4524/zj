<?php

namespace Tests\Feature;

use App\Enums\EscrowStatus;
use App\Filament\Resources\EscrowOrderResource\Pages\ListEscrowOrders;
use App\Models\EscrowOrder;
use App\Models\Notification;
use App\Support\DebugUsers;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

class RefundWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_top_up_does_not_restart_the_refund_window(): void
    {
        $postId = $this->approve($this->pay($this->createPost(1000, '期限豆')));
        $order = EscrowOrder::query()->where('post_id', $postId)->firstOrFail();
        $paidAt = $order->paid_at->copy();

        $this->travel(10)->days();
        $this->asRole('owner')->postJson('/api/posts/'.$postId.'/reward', [
            'add_amount' => 500,
        ])->assertOk();

        $this->assertTrue($paidAt->equalTo($order->refresh()->paid_at));
    }

    public function test_owner_is_warned_before_the_window_closes_and_late_withdraw_stays_offline(): void
    {
        $postId = $this->approve($this->pay($this->createPost(10000, '超期豆')));
        $order = EscrowOrder::query()->where('post_id', $postId)->firstOrFail();

        $this->travel(300)->days();
        Artisan::call('escrows:remind');
        $this->assertSame(0, Notification::query()->where('dedupe_key', 'refund-window:'.$order->id)->count());

        $this->travel(40)->days();
        Artisan::call('escrows:remind');
        $this->assertSame(1, Notification::query()->where('kind', 'refund_window')->where('post_id', $postId)->count());
        Artisan::call('escrows:remind');
        $this->assertSame(1, Notification::query()->where('dedupe_key', 'refund-window:'.$order->id)->count());

        $this->asRole('owner')->getJson('/api/posts/'.$postId)
            ->assertOk()
            ->assertJsonPath('data.refund_route', 'original');

        $this->travel(30)->days();
        $this->asRole('owner')->getJson('/api/posts/'.$postId)
            ->assertJsonPath('data.refund_route', 'offline');

        $this->asRole('owner')->postJson('/api/posts/'.$postId.'/withdraw')
            ->assertOk()
            ->assertJsonPath('data.refund_text', '已撤回。已超过微信退款期限，请联系平台退回托管金。');

        $order->refresh();
        $this->assertSame(EscrowStatus::Held, $order->status);
        $this->assertNotNull(DebugUsers::operator()->notifications()->where('data->dedupe', 'escrow-refund-offline:'.$order->id)->first());

        Livewire::actingAs(DebugUsers::operator())
            ->test(ListEscrowOrders::class)
            ->set('activeTab', 'exception')
            ->assertSee('超期豆')
            ->callTableAction('refundOffline', $order);

        $this->assertSame(EscrowStatus::Refunded, $order->refresh()->status);
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
            'city' => '北海市',
            'lost_place' => '小区北门',
            'lost_story' => '出门后未归',
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
}
