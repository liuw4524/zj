<?php

namespace Tests\Feature;

use App\Enums\AppealStatus;
use App\Enums\EscrowStatus;
use App\Enums\PostStatus;
use App\Models\Appeal;
use App\Models\EscrowOrder;
use App\Models\Lead;
use App\Models\Post;
use App\Models\ReceiptImage;
use App\Models\Setting;
use App\Services\PostService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PostImagePurgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
        Setting::query()->where('key', 'max_active_posts')->update(['value' => '8']);
    }

    public function test_terminal_status_records_closed_at_once(): void
    {
        $withdrawnId = $this->approve($this->createPost(0, '撤回'));
        $this->asRole('owner')->postJson('/api/posts/'.$withdrawnId.'/withdraw')->assertOk();
        $withdrawn = Post::query()->findOrFail($withdrawnId);
        $this->assertSame(PostStatus::Withdrawn, $withdrawn->status);
        $this->assertNotNull($withdrawn->closed_at);
        $stamp = $withdrawn->closed_at->toDateTimeString();
        $this->travel(2)->hours();
        $this->asRole('owner')->postJson('/api/posts/'.$withdrawnId.'/withdraw')->assertOk();
        $this->assertSame($stamp, $withdrawn->fresh()->closed_at->toDateTimeString());

        $rejectedId = $this->createPost(0, '驳回');
        app(PostService::class)->reject(Post::query()->findOrFail($rejectedId), '资料不全');
        $this->assertNotNull(Post::query()->findOrFail($rejectedId)->closed_at);

        $closedId = $this->approve($this->createPost(0, '结案'));
        app(PostService::class)->closeSettled(Post::query()->findOrFail($closedId));
        $closed = Post::query()->findOrFail($closedId);
        $this->assertSame(PostStatus::Closed, $closed->status);
        $this->assertNotNull($closed->closed_at);
    }

    public function test_purge_removes_images_after_six_months_and_keeps_the_order(): void
    {
        $postId = $this->approve($this->pay($this->createPost(1000, '半年')));
        $leadId = $this->submitLead($postId);
        $post = Post::query()->findOrFail($postId);
        $lead = Lead::query()->findOrFail($leadId);
        $postPath = $post->images()->value('path');
        $leadPath = $lead->images()->value('path');
        $missingPath = 'uploads/'.$post->user_id.'/missing.jpg';
        $lead->images()->create(['path' => $missingPath, 'sort' => 1]);
        $receiptPath = 'uploads/'.$post->user_id.'/receipt.jpg';
        Storage::disk('public')->put($receiptPath, 'receipt');
        ReceiptImage::query()->create([
            'lead_id' => $lead->id,
            'path' => $receiptPath,
            'sort' => 0,
        ]);
        $order = EscrowOrder::query()->where('post_id', $postId)->firstOrFail();
        $order->status = EscrowStatus::Settled;
        $order->settled_at = now();
        $order->lead_id = $lead->id;
        $order->save();
        $this->closeDaysAgo($postId, PostService::IMAGE_PURGE_DAYS + 1);

        $heldId = $this->approve($this->pay($this->createPost(1000, '托管中')));
        $heldPath = Post::query()->findOrFail($heldId)->images()->value('path');
        $this->closeDaysAgo($heldId, PostService::IMAGE_PURGE_DAYS + 1);

        $offlineId = $this->approve($this->pay($this->createPost(1000, '待结清')));
        EscrowOrder::query()->where('post_id', $offlineId)->update(['status' => EscrowStatus::PendingOffline->value]);
        $offlinePath = Post::query()->findOrFail($offlineId)->images()->value('path');
        $this->closeDaysAgo($offlineId, PostService::IMAGE_PURGE_DAYS + 1);

        $appealId = $this->approve($this->pay($this->createPost(1000, '申诉')));
        EscrowOrder::query()->where('post_id', $appealId)->update(['status' => EscrowStatus::Settled->value]);
        $appeal = new Appeal();
        $appeal->post_id = $appealId;
        $appeal->user_id = $post->user_id;
        $appeal->content = '还在处理';
        $appeal->status = AppealStatus::Pending;
        $appeal->save();
        $appealPath = Post::query()->findOrFail($appealId)->images()->value('path');
        $this->closeDaysAgo($appealId, PostService::IMAGE_PURGE_DAYS + 1);

        $youngId = $this->approve($this->createPost(0, '未满'));
        $youngPath = Post::query()->findOrFail($youngId)->images()->value('path');
        $this->closeDaysAgo($youngId, PostService::IMAGE_PURGE_DAYS - 1);

        $this->assertSame(1, app(PostService::class)->purgeExpiredImages());

        Storage::disk('public')->assertMissing($postPath);
        Storage::disk('public')->assertMissing($leadPath);
        Storage::disk('public')->assertMissing($receiptPath);
        $this->assertDatabaseMissing('post_images', ['post_id' => $postId]);
        $this->assertDatabaseMissing('lead_images', ['lead_id' => $leadId]);
        $this->assertDatabaseMissing('receipt_images', ['lead_id' => $leadId]);
        $this->assertNotNull(Post::query()->findOrFail($postId)->images_purged_at);
        $this->assertDatabaseHas('posts', ['id' => $postId, 'pet_name' => '半年', 'description' => '于小区走失']);
        $this->assertDatabaseHas('leads', ['id' => $leadId, 'content' => '在公园看到相似的狗']);
        $this->assertDatabaseHas('escrow_orders', [
            'id' => $order->id,
            'post_id' => $postId,
            'lead_id' => $lead->id,
            'status' => EscrowStatus::Settled->value,
        ]);

        Storage::disk('public')->assertExists($heldPath);
        Storage::disk('public')->assertExists($offlinePath);
        Storage::disk('public')->assertExists($appealPath);
        Storage::disk('public')->assertExists($youngPath);
        $this->assertNull(Post::query()->findOrFail($heldId)->images_purged_at);

        $this->asRole('owner')->getJson('/api/posts/'.$postId)
            ->assertOk()
            ->assertJsonPath('data.images', [])
            ->assertJsonPath('data.images_purged', true)
            ->assertJsonPath('data.pet_name', '半年');
        $this->asRole('clue')->getJson('/api/leads/'.$leadId)
            ->assertOk()
            ->assertJsonPath('data.images', [])
            ->assertJsonPath('data.images_purged', true)
            ->assertJsonPath('data.content', '在公园看到相似的狗');

        $this->artisan('posts:purge-images')->assertOk()->expectsOutputToContain('清理 0 条丢失帖的图片');
    }

    private function closeDaysAgo(int $postId, int $days): void
    {
        $post = Post::query()->findOrFail($postId);
        $post->status = PostStatus::Closed;
        $post->closed_at = now()->subDays($days);
        $post->active_key = null;
        $post->lit_until = null;
        $post->save();
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
        ])->assertOk()->json('data.id');
    }

    private function pay(int $postId): int
    {
        $this->asRole('owner')->postJson('/api/posts/'.$postId.'/pay')->assertOk();

        return $postId;
    }

    private function approve(int $postId): int
    {
        app(PostService::class)->approve(Post::query()->findOrFail($postId));

        return $postId;
    }

    private function submitLead(int $postId): int
    {
        return $this->asRole('clue')->postJson('/api/posts/'.$postId.'/leads', [
            'kind' => 'held',
            'content' => '在公园看到相似的狗',
            'contact_name' => '线索人',
            'contact_phone' => '13900000002',
            'phone' => '13900000002',
            'images' => [$this->upload('clue')],
        ])->assertOk()->json('data.id');
    }
}
