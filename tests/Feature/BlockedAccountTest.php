<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use App\Support\DebugUsers;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class BlockedAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_blocked_user_cannot_continue_lost_pet_actions(): void
    {
        $postId = $this->approve($this->createPost('仍可看'));
        User::query()->where('email', DebugUsers::CLUE)->update(['is_blocked' => true]);

        $this->asRole('clue')->postJson('/api/posts/'.$postId.'/leads', $this->leadPayload())
            ->assertStatus(403)
            ->assertJsonPath('message', '账号已限制');

        User::query()->where('email', DebugUsers::CLUE)->update(['is_blocked' => false]);
        $leadId = $this->asRole('clue')->postJson('/api/posts/'.$postId.'/leads', $this->leadPayload())
            ->assertOk()
            ->json('data.id');

        User::query()->where('email', DebugUsers::OWNER)->update(['is_blocked' => true]);
        $this->asRole('owner')->postJson('/api/leads/'.$leadId.'/match')
            ->assertStatus(403)
            ->assertJsonPath('message', '账号已限制');
        $this->asRole('owner')->postJson('/api/posts/'.$postId.'/withdraw')
            ->assertStatus(403)
            ->assertJsonPath('message', '账号已限制');

        User::query()->where('email', DebugUsers::OWNER)->update(['is_blocked' => false]);
        $this->asRole('owner')->postJson('/api/leads/'.$leadId.'/match')->assertOk();
        User::query()->where('email', DebugUsers::OWNER)->update(['is_blocked' => true]);
        $this->asRole('owner')->postJson('/api/leads/'.$leadId.'/confirm')
            ->assertStatus(403)->assertJsonPath('message', '账号已限制');
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
            'reward_amount' => 0,
            'contact_name' => '宠主',
            'contact_phone' => '13800000001',
            'phone' => '13800000001',
            'escrow_to_platform' => false,
            'images' => [$this->upload('owner')],
        ])->assertOk()->json('data.id');
    }

    private function approve(int $postId): int
    {
        app(\App\Services\PostService::class)->approve(Post::query()->findOrFail($postId));

        return $postId;
    }

    private function leadPayload(): array
    {
        return [
            'kind' => 'held',
            'content' => '狗在我这里',
            'contact_name' => '线索人',
            'contact_phone' => '13900000002',
            'phone' => '13900000002',
            'images' => [$this->upload('clue')],
        ];
    }
}
