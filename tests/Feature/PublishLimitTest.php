<?php

namespace Tests\Feature;

use App\Models\Setting;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublishLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
    }

    public function test_lost_post_allows_five_images_and_rejects_six(): void
    {
        $this->asRole('owner')->postJson('/api/posts', $this->postPayload(6))
            ->assertStatus(422)
            ->assertJsonPath('message', '最多上传 5 张照片');

        $this->asRole('owner')->postJson('/api/posts', $this->postPayload(5))
            ->assertOk()
            ->assertJsonCount(5, 'data.images');
    }

    public function test_clue_post_allows_four_images_and_rejects_five(): void
    {
        $this->asRole('owner')->postJson('/api/clue-posts', $this->cluePayload(5))
            ->assertStatus(422)
            ->assertJsonPath('message', '最多上传 4 张照片');

        $this->asRole('owner')->postJson('/api/clue-posts', $this->cluePayload(4))
            ->assertOk()
            ->assertJsonCount(4, 'data.images');
    }

    public function test_daily_clue_cap_resets_the_next_day(): void
    {
        Setting::query()->where('key', 'max_active_clue_posts')->update(['value' => '10']);
        Setting::query()->where('key', 'max_daily_clue_posts')->update(['value' => '2']);

        $this->asRole('owner')->postJson('/api/clue-posts', $this->cluePayload(1))->assertOk();
        $this->asRole('owner')->postJson('/api/clue-posts', $this->cluePayload(1))->assertOk();
        $this->asRole('owner')->postJson('/api/clue-posts', $this->cluePayload(1))
            ->assertStatus(422)
            ->assertJsonPath('message', '今日线索帖已达上限');

        $this->travel(1)->days();

        $this->asRole('owner')->postJson('/api/clue-posts', $this->cluePayload(1))->assertOk();
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

    private function postPayload(int $images): array
    {
        return [
            'pet_name' => '奶糖',
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
            'images' => $this->paths($images),
        ];
    }

    private function cluePayload(int $images): array
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
            'images' => $this->paths($images),
        ];
    }

    private function paths(int $count): array
    {
        $paths = [];
        for ($i = 0; $i < $count; $i++) {
            $paths[] = $this->upload();
        }

        return $paths;
    }
}
