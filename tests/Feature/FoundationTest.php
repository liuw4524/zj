<?php

namespace Tests\Feature;

use App\Models\Setting;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_debug_owner_and_settings_are_available(): void
    {
        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.debug_role', 'owner')
            ->assertJsonPath('data.phone', '13800000001')
            ->assertJsonPath('data.is_blocked', false);

        $this->getJson('/api/me', ['X-Debug-User' => 'clue'])
            ->assertOk()
            ->assertJsonPath('data.debug_role', 'clue');

        $this->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.commission_rate', 15)
            ->assertJsonPath('data.reward_cap_fen', 600000)
            ->assertJsonPath('data.max_active_posts', 1)
            ->assertJsonPath('data.home_today_new', 0)
            ->assertJsonPath('data.home_in_progress', 0)
            ->assertJsonPath('data.home_found', 0);

        Setting::query()->where('key', 'home_today_new')->update(['value' => '12']);
        Setting::query()->where('key', 'home_in_progress')->update(['value' => '34']);
        Setting::query()->where('key', 'home_found')->update(['value' => '5']);

        $this->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.home_today_new', 12)
            ->assertJsonPath('data.home_in_progress', 34)
            ->assertJsonPath('data.home_found', 5);

        $this->assertSame(15, Setting::getInt('commission_rate'));
    }

    public function test_auth_disabled_off_rejects_api(): void
    {
        config(['app.auth_disabled' => false]);

        $this->getJson('/api/me')->assertStatus(401);
    }
}
