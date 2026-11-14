<?php

namespace Tests\Feature;

use App\Enums\CluePostStatus;
use App\Enums\GrowthSource;
use App\Enums\MedalLevel;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\CluePost;
use App\Models\GrowthLog;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Support\DebugUsers;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class GrowthMedalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_new_user_is_bronze_and_can_edit_profile(): void
    {
        $this->asRole('owner')->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.medal.level', MedalLevel::Bronze->value)
            ->assertJsonPath('data.medal.title', '公益新爪')
            ->assertJsonPath('data.medal.points', 0)
            ->assertJsonPath('data.medal.points_to_next', 7)
            ->assertJsonPath('data.medal.daily_claimed', false);

        $this->asRole('owner')->patchJson('/api/me', [
            'name' => '小爪',
            'phone' => '+86 136-0000-0009',
            'province' => '浙江省',
            'city' => '杭州市',
            'district' => '西湖区',
            'address' => '北门',
            'is_blocked' => true,
            'growth_points' => 999,
            'medal_level' => 5,
        ])->assertOk()
            ->assertJsonPath('data.name', '小爪')
            ->assertJsonPath('data.phone', '13600000009')
            ->assertJsonPath('data.province', '浙江省')
            ->assertJsonPath('data.city', '杭州市')
            ->assertJsonPath('data.district', '西湖区')
            ->assertJsonPath('data.address', '北门')
            ->assertJsonPath('data.is_blocked', false)
            ->assertJsonPath('data.medal.points', 0)
            ->assertJsonPath('data.medal.level', MedalLevel::Bronze->value);

        $this->asRole('owner')->patchJson('/api/me', [
            'name' => '小爪',
            'phone' => '123',
        ])->assertStatus(422)->assertJsonPath('message', '请填写11位手机号');

        $this->asRole('owner')->patchJson('/api/me', [
            'name' => '小爪',
            'phone' => '13600000009',
            'city' => '杭州市',
        ])->assertStatus(422)->assertJsonPath('message', '请选择完整地址');

        $this->asRole('owner')->getJson('/api/me/growth')
            ->assertOk()
            ->assertJsonCount(5, 'data.levels')
            ->assertJsonPath('data.levels.4.title', '爪迹王者')
            ->assertJsonPath('data.levels.4.threshold', 120);
    }

    public function test_daily_open_counts_once_per_day_and_reaches_silver_in_seven_days(): void
    {
        $start = now()->startOfDay();
        for ($i = 0; $i < 7; $i++) {
            $this->travelTo($start->copy()->addDays($i)->setTime(8, 0));
            $this->asRole('owner')->postJson('/api/me/visit')
                ->assertOk()
                ->assertJsonPath('data.growth_awarded', 1)
                ->assertJsonPath('data.medal.daily_claimed', true);
            $this->asRole('owner')->postJson('/api/me/visit')
                ->assertJsonPath('data.growth_awarded', 0);
        }

        $owner = DebugUsers::resolve('owner')->refresh();
        $this->assertSame(7, $owner->growth_points);
        $this->assertSame(MedalLevel::Silver->value, $owner->medal_level);

        $this->asRole('owner')->getJson('/api/me')
            ->assertJsonPath('data.medal.title', '热心探爪')
            ->assertJsonPath('data.medal.points_to_next', 18);

        $this->asRole('owner')->getJson('/api/me/growth')
            ->assertJsonPath('data.daily_claimed', true)
            ->assertJsonPath('data.logs.0.source_text', '点亮爪印');
    }

    public function test_clue_review_adds_points_and_lost_post_review_does_not(): void
    {
        $lostId = $this->createPost(0, '丢失');
        app(\App\Services\PostService::class)->approve(Post::query()->findOrFail($lostId));
        $this->assertSame(0, DebugUsers::resolve('owner')->refresh()->growth_points);

        $rejected = $this->createClue();
        app(\App\Services\CluePostService::class)->reject(CluePost::query()->findOrFail($rejected), '看不清');
        $this->assertSame(0, DebugUsers::resolve('owner')->refresh()->growth_points);

        $clueId = $this->createClue();
        app(\App\Services\CluePostService::class)->approve(CluePost::query()->findOrFail($clueId));

        $owner = DebugUsers::resolve('owner')->refresh();
        $this->assertSame(5, $owner->growth_points);
        $this->assertSame(MedalLevel::Bronze->value, $owner->medal_level);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'title' => '公益成长值 +5',
            'kind' => 'growth',
        ]);

        $this->asRole('owner')->postJson('/api/clue-posts/'.$clueId.'/light')->assertOk();
        $this->asRole('owner')->postJson('/api/clue-posts/'.$clueId.'/withdraw')->assertOk();
        $this->assertSame(5, $owner->refresh()->growth_points);
        $this->assertSame(1, GrowthLog::query()->where('source', GrowthSource::ClueApproved)->count());
    }

    public function test_submitting_a_lead_adds_five_and_rejection_does_not_deduct(): void
    {
        Setting::query()->where('key', 'max_active_posts')->update(['value' => '2']);
        $first = $this->approvePost($this->createPost(0, '第一只'));
        $second = $this->approvePost($this->createPost(0, '第二只'));

        $leadId = $this->asRole('clue')->postJson('/api/posts/'.$first.'/leads', $this->leadPayload('clue'))
            ->assertOk()
            ->assertJsonPath('data.growth_awarded', 5)
            ->json('data.id');

        $this->asRole('owner')->postJson('/api/leads/'.$leadId.'/reject')->assertOk();
        $clue = DebugUsers::resolve('clue')->refresh();
        $this->assertSame(5, $clue->growth_points);

        $this->asRole('clue')->postJson('/api/posts/'.$second.'/leads', $this->leadPayload('clue'))
            ->assertOk()
            ->assertJsonPath('data.growth_awarded', 5);

        $clue->refresh();
        $this->assertSame(10, $clue->growth_points);
        $this->assertSame(MedalLevel::Silver->value, $clue->medal_level);
        $this->asRole('clue')->getJson('/api/me/growth')
            ->assertJsonPath('data.logs.0.source_text', '提交线索');
    }

    public function test_share_respects_same_post_and_daily_cap_for_both_post_types(): void
    {
        Setting::query()->where('key', 'max_active_posts')->update(['value' => '4']);
        $posts = [];
        foreach (['甲', '乙', '丙', '丁'] as $name) {
            $posts[] = $this->approvePost($this->createPost(0, $name));
        }
        $clueId = $this->createClue();
        app(\App\Services\CluePostService::class)->approve(CluePost::query()->findOrFail($clueId));

        $this->asRole('clue')->postJson('/api/posts/'.$posts[0].'/share', ['growth' => 1])
            ->assertOk()
            ->assertJsonPath('data.growth_awarded', 2);
        $this->asRole('clue')->postJson('/api/posts/'.$posts[0].'/share', ['growth' => 1])
            ->assertJsonPath('data.growth_awarded', 0)
            ->assertJsonPath('data.share_count', 2);
        $this->asRole('clue')->postJson('/api/posts/'.$posts[1].'/share', ['growth' => 1])
            ->assertJsonPath('data.growth_awarded', 2);
        $this->asRole('clue')->postJson('/api/clue-posts/'.$clueId.'/share')
            ->assertOk()
            ->assertJsonPath('data.growth_awarded', 2);
        $this->asRole('clue')->postJson('/api/posts/'.$posts[2].'/share', ['growth' => 1])
            ->assertJsonPath('data.growth_awarded', 0);

        $clue = DebugUsers::resolve('clue')->refresh();
        $this->assertSame(6, $clue->growth_points);

        $this->asRole('clue')->postJson('/api/posts/'.$posts[2].'/share')
            ->assertJsonPath('data.growth_awarded', 0)
            ->assertJsonPath('data.share_count', 2);

        $this->travelTo(now()->addDay());
        $this->asRole('clue')->postJson('/api/posts/'.$posts[2].'/share', ['growth' => 1])
            ->assertJsonPath('data.growth_awarded', 2);
        $this->assertSame(8, $clue->refresh()->growth_points);

        $pending = $this->createClue();
        $this->asRole('owner')->postJson('/api/clue-posts/'.$pending.'/share')
            ->assertOk()
            ->assertJsonPath('data.growth_awarded', 0);
    }

    public function test_blocked_user_stops_earning_and_keeps_the_medal(): void
    {
        $postId = $this->approvePost($this->createPost(0, '仍可看'));
        $owner = DebugUsers::resolve('owner');
        $owner->growth_points = 25;
        $owner->medal_level = MedalLevel::Gold->value;
        $owner->is_blocked = true;
        $owner->save();

        $this->asRole('owner')->postJson('/api/me/visit')
            ->assertOk()
            ->assertJsonPath('data.growth_awarded', 0)
            ->assertJsonPath('data.medal.level', MedalLevel::Gold->value)
            ->assertJsonPath('data.medal.title', '公益达人')
            ->assertJsonPath('data.medal.points', 25);

        User::query()->where('email', DebugUsers::CLUE)->update(['is_blocked' => true]);
        $this->asRole('clue')->postJson('/api/posts/'.$postId.'/leads', $this->leadPayload('clue'))
            ->assertStatus(403)
            ->assertJsonPath('message', '账号已限制');
        $this->asRole('clue')->postJson('/api/posts/'.$postId.'/share', ['growth' => 1])
            ->assertJsonPath('data.growth_awarded', 0);
        $this->assertSame(0, DebugUsers::resolve('clue')->refresh()->growth_points);
        $this->assertSame(25, $owner->refresh()->growth_points);
        $this->assertSame(MedalLevel::Gold->value, $owner->medal_level);
    }

    public function test_admin_adjustment_is_logged_and_never_lowers_the_medal(): void
    {
        $owner = DebugUsers::resolve('owner');

        Livewire::actingAs(DebugUsers::operator())
            ->test(ListUsers::class)
            ->callTableAction('adjustGrowth', $owner, data: [
                'delta' => 120,
                'note' => '补记公益成长值',
            ])
            ->assertHasNoTableActionErrors();

        $owner->refresh();
        $this->assertSame(120, $owner->growth_points);
        $this->assertSame(MedalLevel::King->value, $owner->medal_level);
        $this->assertDatabaseHas('growth_logs', [
            'user_id' => $owner->id,
            'points' => 120,
            'source' => GrowthSource::Admin->value,
            'note' => '补记公益成长值',
            'operator_id' => DebugUsers::operator()->id,
            'medal_level_after' => MedalLevel::King->value,
        ]);

        Livewire::actingAs(DebugUsers::operator())
            ->test(ListUsers::class)
            ->callTableAction('adjustGrowth', $owner, data: [
                'delta' => -100,
                'note' => '多记了',
            ])
            ->assertHasNoTableActionErrors();

        $owner->refresh();
        $this->assertSame(20, $owner->growth_points);
        $this->assertSame(MedalLevel::King->value, $owner->medal_level);

        Livewire::actingAs(DebugUsers::operator())
            ->test(ListUsers::class)
            ->callTableAction('adjustGrowth', $owner, data: [
                'delta' => -100,
                'note' => '减到零',
            ])
            ->assertHasNoTableActionErrors();

        $owner->refresh();
        $this->assertSame(0, $owner->growth_points);
        $this->assertSame(MedalLevel::King->value, $owner->medal_level);
        $this->assertDatabaseHas('growth_logs', [
            'user_id' => $owner->id,
            'points' => -20,
            'balance_after' => 0,
        ]);

        Livewire::actingAs(DebugUsers::operator())
            ->test(ListUsers::class)
            ->callTableAction('adjustGrowth', $owner, data: [
                'delta' => -1,
                'note' => '再减',
            ])
            ->assertHasErrors(['delta']);

        $this->assertSame(0, $owner->refresh()->growth_points);
        $this->asRole('owner')->getJson('/api/me')
            ->assertJsonPath('data.medal.title', '爪迹王者')
            ->assertJsonPath('data.medal.points_to_next', null);

        Livewire::actingAs(DebugUsers::operator())
            ->test(ListUsers::class)
            ->mountTableAction('growthLogs', $owner)
            ->assertSee('补记公益成长值');
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
            'escrow_to_platform' => false,
            'images' => [$this->upload('owner')],
        ])->assertOk()->json('data.id');
    }

    private function approvePost(int $postId): int
    {
        app(\App\Services\PostService::class)->approve(Post::query()->findOrFail($postId));

        return $postId;
    }

    private function createClue(): int
    {
        return $this->asRole('owner')->postJson('/api/clue-posts', [
            'description' => '小区门口看到一只黄狗',
            'province' => '浙江省',
            'city' => '杭州市',
            'found_at' => now()->subHour()->toDateTimeString(),
            'contact_name' => '路人',
            'contact_phone' => '13800000001',
            'phone' => '13800000001',
            'contact_public' => false,
            'images' => [$this->upload('owner')],
        ])->assertOk()->assertJsonPath('data.status', CluePostStatus::PendingReview->value)->json('data.id');
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
}
