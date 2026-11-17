<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Filament\Resources\PostResource\Pages\ListPosts;
use App\Filament\Resources\PostResource\Pages\ViewPost;
use App\Models\Post;
use App\Models\Setting;
use App\Support\DebugUsers;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class TraceCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
        Setting::query()->where('key', 'max_active_posts')->update(['value' => '5']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_code_is_issued_when_the_post_is_actually_published(): void
    {
        $free = $this->asRole('owner')->postJson('/api/posts', $this->postPayload(0, '无偿'))
            ->assertOk()
            ->json('data');
        $this->assertTraceCode($free['trace_code']);
        $this->assertSame('抖音搜/小程序搜爪迹编号 '.$free['trace_code'], $free['trace_share_text']);

        $waiting = $this->asRole('owner')->postJson('/api/posts', $this->postPayload(8000, '待付'))
            ->assertOk()
            ->assertJsonPath('data.status', PostStatus::PendingPayment->value)
            ->assertJsonPath('data.trace_code', null)
            ->assertJsonPath('data.trace_share_text', null)
            ->json('data.id');

        $this->asRole('owner')->postJson('/api/posts/'.$waiting.'/pay')->assertOk();
        $paid = Post::query()->findOrFail($waiting);
        $this->assertTraceCode($paid->trace_code);
        $this->asRole('owner')->postJson('/api/posts/'.$waiting.'/pay')->assertOk();
        $this->assertSame($paid->trace_code, $paid->fresh()->trace_code);

        $kept = $this->postPayload(8000, '自结');
        $kept['escrow_to_platform'] = false;
        $self = $this->asRole('owner')->postJson('/api/posts', $kept)
            ->assertOk()
            ->assertJsonPath('data.status', PostStatus::PendingReview->value)
            ->json('data.trace_code');
        $this->assertTraceCode($self);

        $codes = [$free['trace_code'], $paid->trace_code, $self];
        $this->assertCount(3, array_unique($codes));

        $this->asRole('owner')->getJson('/api/my/posts')
            ->assertOk()
            ->assertJsonFragment(['trace_code' => $free['trace_code']]);
    }

    public function test_exact_code_opens_a_public_post_and_hides_unavailable_ones(): void
    {
        $created = $this->asRole('owner')->postJson('/api/posts', $this->postPayload(0, '待审'))
            ->assertOk()
            ->json('data');
        $code = $created['trace_code'];

        $this->asRole('owner')->getJson('/api/posts/trace?code='.urlencode(strtolower($code)))
            ->assertOk()
            ->assertJsonPath('data.pet_name', '待审')
            ->assertJsonPath('data.trace_code', $code);
        $this->asRole('clue')->getJson('/api/posts/trace?code='.urlencode($code))
            ->assertStatus(404)
            ->assertJsonPath('message', '帖子不存在');

        app(\App\Services\PostService::class)->approve(Post::query()->findOrFail($created['id']));

        $spaced = substr($code, 0, 2).' '.substr(strtolower($code), 2);
        $this->asRole('clue')->getJson('/api/posts/trace?code='.urlencode($spaced))
            ->assertOk()
            ->assertJsonPath('data.id', $created['id'])
            ->assertJsonMissingPath('data.contact_phone');

        $this->asRole('clue')->getJson('/api/posts/trace?code='.urlencode(substr($code, 0, 5)))
            ->assertStatus(404)
            ->assertJsonPath('message', '未找到该爪迹编号');
        $this->asRole('clue')->getJson('/api/posts/trace?code=没有这个号')
            ->assertStatus(404)
            ->assertJsonPath('message', '未找到该爪迹编号');

        $this->asRole('owner')->postJson('/api/posts/'.$created['id'].'/withdraw')->assertOk();
        $this->assertSame($code, Post::query()->findOrFail($created['id'])->trace_code);
        $hidden = $this->asRole('clue')->getJson('/api/posts/trace?code='.urlencode($code));
        $hidden->assertStatus(404)->assertJsonPath('message', '帖子已下架或不存在');
        $this->assertStringNotContainsString('13800000001', $hidden->getContent());
        $this->assertStringNotContainsString('于小区走失', $hidden->getContent());

        $this->asRole('owner')->getJson('/api/posts/'.$created['id'])
            ->assertOk()
            ->assertJsonPath('data.trace_code', $code);
    }

    public function test_closed_and_frozen_posts_are_not_opened_by_code(): void
    {
        $id = $this->asRole('owner')->postJson('/api/posts', $this->postPayload(0, '结案'))
            ->assertOk()
            ->json('data.id');
        app(\App\Services\PostService::class)->approve(Post::query()->findOrFail($id));
        $post = Post::query()->findOrFail($id);
        $post->status = PostStatus::Closed;
        $post->save();

        $this->asRole('owner')->getJson('/api/posts/trace?code='.urlencode($post->trace_code))
            ->assertStatus(404)
            ->assertJsonPath('message', '帖子已下架或不存在');

        $post->status = PostStatus::Frozen;
        $post->save();
        $this->asRole('clue')->getJson('/api/posts/trace?code='.urlencode($post->trace_code))
            ->assertStatus(404)
            ->assertJsonPath('message', '帖子已下架或不存在');
    }

    public function test_admin_can_see_and_search_the_code(): void
    {
        $created = $this->asRole('owner')->postJson('/api/posts', $this->postPayload(0, '后台'))
            ->assertOk()
            ->json('data');

        Livewire::actingAs(DebugUsers::operator())
            ->test(ListPosts::class)
            ->searchTable($created['trace_code'])
            ->assertCanSeeTableRecords([Post::query()->findOrFail($created['id'])]);

        Livewire::actingAs(DebugUsers::operator())
            ->test(ViewPost::class, ['record' => $created['id']])
            ->assertSee($created['trace_code']);
    }

    private function assertTraceCode(string $code): void
    {
        $this->assertMatchesRegularExpression('/^ZJ[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}$/', $code);
    }

    private function asRole(string $role): static
    {
        return $this->withHeader('X-Debug-User', $role);
    }

    private function postPayload(int $reward, string $name): array
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

    private function upload(): string
    {
        return $this->asRole('owner')->post('/api/uploads', [
            'file' => UploadedFile::fake()->image('pet.jpg'),
        ])->assertOk()->json('data.path');
    }
}
