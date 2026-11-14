<?php

namespace Tests\Feature;

use App\Enums\CluePostStatus;
use App\Enums\PostStatus;
use App\Models\Notification;
use App\Models\Setting;
use App\Support\DebugUsers;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PostReviewSettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
    }

    public function test_review_on_keeps_new_posts_pending(): void
    {
        $this->asRole('owner')->postJson('/api/posts', $this->postPayload(0))
            ->assertOk()
            ->assertJsonPath('data.status', PostStatus::PendingReview->value);

        $this->asRole('owner')->postJson('/api/clue-posts', $this->cluePayload())
            ->assertOk()
            ->assertJsonPath('data.status', CluePostStatus::PendingReview->value);

        $this->assertSame(0, DebugUsers::resolve('owner')->refresh()->growth_points);
    }

    public function test_review_off_publishes_new_posts_and_leaves_pending_ones(): void
    {
        Setting::query()->where('key', 'max_active_posts')->update(['value' => '5']);
        $pendingId = $this->asRole('owner')->postJson('/api/posts', $this->postPayload(0, '待审'))
            ->assertOk()
            ->json('data.id');

        Setting::query()->where('key', 'post_review_enabled')->update(['value' => '0']);

        $this->asRole('owner')->getJson('/api/posts/'.$pendingId)
            ->assertOk()
            ->assertJsonPath('data.status', PostStatus::PendingReview->value);

        $shownId = $this->asRole('owner')->postJson('/api/posts', $this->postPayload(0, '直接展示'))
            ->assertOk()
            ->assertJsonPath('data.status', PostStatus::Showing->value)
            ->json('data.id');

        $this->asRole('clue')->getJson('/api/posts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $shownId);

        $paidId = $this->asRole('owner')->postJson('/api/posts', $this->postPayload(100, '托管'))
            ->assertOk()
            ->assertJsonPath('data.status', PostStatus::PendingPayment->value)
            ->json('data.id');
        $this->asRole('owner')->postJson('/api/posts/'.$paidId.'/pay')
            ->assertOk();
        $this->asRole('clue')->getJson('/api/posts/'.$paidId)
            ->assertOk()
            ->assertJsonPath('data.status', PostStatus::Showing->value);

        $clueId = $this->asRole('owner')->postJson('/api/clue-posts', $this->cluePayload())
            ->assertOk()
            ->assertJsonPath('data.status', CluePostStatus::Showing->value)
            ->json('data.id');

        $this->asRole('clue')->getJson('/api/clue-posts?'.http_build_query([
            'province' => '浙江省',
            'city' => '杭州市',
        ]))->assertOk()->assertJsonPath('data.0.id', $clueId);

        $owner = DebugUsers::resolve('owner')->refresh();
        $this->assertSame(5, $owner->growth_points);
        $this->assertTrue(Notification::query()
            ->where('user_id', $owner->id)
            ->where('body', '你的公益线索已发布')
            ->exists());
    }

    private function asRole(string $role): static
    {
        return $this->withHeader('X-Debug-User', $role);
    }

    private function upload(): string
    {
        return $this->asRole('owner')->post('/api/uploads', [
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
            'images' => [$this->upload()],
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
            'images' => [$this->upload()],
        ];
    }
}
