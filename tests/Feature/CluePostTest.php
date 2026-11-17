<?php

namespace Tests\Feature;

use App\Enums\CluePostStatus;
use App\Models\CluePost;
use App\Models\Setting;
use App\Models\User;
use App\Support\DebugUsers;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CluePostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
    }

    public function test_public_list_starts_after_review_and_hides_when_light_expires(): void
    {
        $path = $this->upload('owner');
        $id = $this->asRole('owner')->postJson('/api/clue-posts', $this->payload($path, true))
            ->assertOk()
            ->assertJsonPath('data.published_days', 0)
            ->assertJsonPath('data.status', CluePostStatus::PendingReview->value)
            ->json('data.id');

        $this->asRole('clue')->getJson('/api/clue-posts?'.http_build_query([
            'province' => '浙江省',
            'city' => '杭州市',
        ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $post = CluePost::query()->findOrFail($id);
        app(\App\Services\CluePostService::class)->approve($post);

        $this->asRole('clue')->getJson('/api/clue-posts?'.http_build_query([
            'province' => '浙江省',
            'city' => '杭州市',
        ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.description', '小区门口看到一只黄狗')
            ->assertJsonMissingPath('data.0.contact');

        $this->asRole('clue')->getJson('/api/clue-posts/'.$id)
            ->assertOk()
            ->assertJsonPath('data.contact.phone', '13800000001');

        $post->refresh();
        $this->travelTo($post->lit_until->copy()->addMinute());
        Artisan::call('clue-posts:delist-expired');

        $this->asRole('clue')->getJson('/api/clue-posts?'.http_build_query([
            'province' => '浙江省',
            'city' => '杭州市',
        ]))
            ->assertJsonPath('meta.total', 0);
        $this->asRole('clue')->getJson('/api/clue-posts/'.$id)->assertStatus(404);
        $this->asRole('owner')->getJson('/api/clue-posts/'.$id)->assertOk()
            ->assertJsonPath('data.status', CluePostStatus::Delisted->value);

        $this->asRole('owner')->postJson('/api/clue-posts/'.$id.'/light')->assertOk()
            ->assertJsonPath('data.status', CluePostStatus::Showing->value);
        $this->asRole('clue')->getJson('/api/clue-posts?'.http_build_query([
            'province' => '浙江省',
            'city' => '杭州市',
        ]))
            ->assertJsonPath('meta.total', 1);

        $post->refresh();
        $this->travelTo($post->lit_until->copy()->addMinute());
        Artisan::call('clue-posts:delist-expired');
        $post->refresh();
        $this->travelTo($post->delisted_at->copy()->addDays(30)->addMinute());
        Artisan::call('clue-posts:purge-stale');

        $this->assertDatabaseMissing('clue_posts', ['id' => $id]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_private_contact_is_visible_only_to_the_approved_applicant(): void
    {
        $id = $this->asRole('owner')->postJson('/api/clue-posts', $this->payload($this->upload('owner'), false))
            ->assertOk()
            ->json('data.id');
        app(\App\Services\CluePostService::class)->approve(CluePost::query()->findOrFail($id));

        $this->asRole('clue')->getJson('/api/clue-posts/'.$id)
            ->assertOk()
            ->assertJsonPath('data.contact', null)
            ->assertJsonPath('data.can_request_contact', true);

        $requestId = $this->asRole('clue')->postJson('/api/clue-posts/'.$id.'/contact-requests')
            ->assertOk()
            ->json('data.id');
        $this->asRole('clue')->postJson('/api/clue-posts/'.$id.'/contact-requests')->assertStatus(422);
        $this->asRole('owner')->getJson('/api/my/clue-contact-requests/pending-count')
            ->assertOk()
            ->assertJsonPath('data.count', 1);
        $this->asRole('owner')->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonFragment([
                'kind' => 'clue_contact_request',
                'title' => '有人申请查看联系方式',
                'clue_post_id' => $id,
            ]);

        $this->asRole('owner')->postJson('/api/clue-contact-requests/'.$requestId.'/approve')->assertOk();
        $this->asRole('owner')->getJson('/api/my/clue-contact-requests/pending-count')
            ->assertOk()
            ->assertJsonPath('data.count', 0);
        $this->asRole('clue')->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonFragment([
                'kind' => 'clue_contact_approved',
                'title' => '联系方式申请已通过',
                'clue_post_id' => $id,
            ]);
        $this->asRole('clue')->getJson('/api/my/clue-contact-requests/pending-count')
            ->assertOk()
            ->assertJsonPath('data.results', 1)
            ->assertJsonPath('data.count', 1);
        $noticeId = $this->asRole('clue')->getJson('/api/notifications')
            ->json('data.0.id');
        $this->asRole('clue')->postJson('/api/notifications/'.$noticeId.'/read')->assertOk();
        $this->asRole('clue')->getJson('/api/my/clue-contact-requests/pending-count')
            ->assertOk()
            ->assertJsonPath('data.results', 0);

        $this->asRole('clue')->getJson('/api/clue-posts/'.$id)
            ->assertJsonPath('data.contact.phone', '13800000001');
        $this->asRole('ops')->getJson('/api/clue-posts/'.$id)
            ->assertJsonPath('data.contact', null);

        $path = CluePost::query()->findOrFail($id)->images()->value('path');
        $this->asRole('owner')->postJson('/api/clue-posts/'.$id.'/withdraw')->assertOk();
        Storage::disk('public')->assertMissing($path);
        $this->asRole('clue')->getJson('/api/clue-posts/'.$id)->assertStatus(404);
    }

    public function test_rejected_contact_request_notifies_the_applicant(): void
    {
        $id = $this->asRole('owner')->postJson('/api/clue-posts', $this->payload($this->upload('owner'), false))
            ->assertOk()
            ->json('data.id');
        app(\App\Services\CluePostService::class)->approve(CluePost::query()->findOrFail($id));
        $requestId = $this->asRole('clue')->postJson('/api/clue-posts/'.$id.'/contact-requests')
            ->assertOk()
            ->json('data.id');

        $this->asRole('owner')->postJson('/api/clue-contact-requests/'.$requestId.'/reject')->assertOk();
        $this->asRole('clue')->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonFragment([
                'kind' => 'clue_contact_rejected',
                'title' => '联系方式申请未通过',
                'clue_post_id' => $id,
            ]);
        $this->asRole('clue')->getJson('/api/my/clue-contact-requests')->assertOk();
        $this->asRole('clue')->getJson('/api/my/clue-contact-requests/pending-count')
            ->assertOk()
            ->assertJsonPath('data.results', 0);
    }

    public function test_blocked_user_and_active_cap(): void
    {
        User::query()->where('email', DebugUsers::OWNER)->update(['is_blocked' => true]);
        $this->asRole('owner')->postJson('/api/clue-posts', $this->payload($this->upload('owner'), false))
            ->assertStatus(403);

        User::query()->where('email', DebugUsers::OWNER)->update(['is_blocked' => false]);
        Setting::query()->where('key', 'max_active_clue_posts')->update(['value' => '1']);
        $this->asRole('owner')->postJson('/api/clue-posts', $this->payload($this->upload('owner'), false))->assertOk();
        $this->asRole('owner')->postJson('/api/clue-posts', $this->payload($this->upload('owner'), false))
            ->assertStatus(422)
            ->assertJsonPath('message', '已达到同时有效线索帖上限');
    }

    private function asRole(string $role): static
    {
        return $this->withHeader('X-Debug-User', $role);
    }

    private function upload(string $role): string
    {
        return $this->asRole($role)->post('/api/uploads', [
            'file' => UploadedFile::fake()->image('sight.jpg'),
        ])->assertOk()->json('data.path');
    }

    private function payload(string $path, bool $public): array
    {
        return [
            'description' => '小区门口看到一只黄狗',
            'province' => '浙江省',
            'city' => '杭州市',
            'found_at' => now()->subHour()->toDateTimeString(),
            'contact_name' => '路人',
            'contact_phone' => '13800000001',
            'phone' => '13800000001',
            'contact_public' => $public,
            'images' => [$path],
        ];
    }
}
