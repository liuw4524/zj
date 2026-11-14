<?php

namespace Tests\Feature;

use App\Filament\Resources\SettingResource\Pages\EditSetting;
use App\Filament\Resources\SettingResource\Pages\ListSettings;
use App\Models\Setting;
use App\Support\DebugUsers;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class RescueTeamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
    }

    public function test_rescue_team_image_is_empty_until_uploaded(): void
    {
        $this->getJson('/api/rescue-team')
            ->assertOk()
            ->assertJsonPath('data.url', null);

        Storage::disk('public')->put('rescue-team/wechat.png', 'qr');
        Setting::query()->where('key', 'rescue_team_image')->update(['value' => 'rescue-team/wechat.png']);

        $this->getJson('/api/rescue-team')
            ->assertOk()
            ->assertJsonPath('data.url', Storage::disk('public')->url('rescue-team/wechat.png'));
    }

    public function test_contact_platform_image_is_separate_from_rescue_team(): void
    {
        $this->getJson('/api/contact-platform')
            ->assertOk()
            ->assertJsonPath('data.url', null);

        Storage::disk('public')->put('contact-platform/wechat.png', 'qr');
        Setting::query()->where('key', 'contact_platform_image')->update(['value' => 'contact-platform/wechat.png']);

        $this->getJson('/api/contact-platform')
            ->assertOk()
            ->assertJsonPath('data.url', Storage::disk('public')->url('contact-platform/wechat.png'));
        $this->getJson('/api/rescue-team')
            ->assertOk()
            ->assertJsonPath('data.url', null);
    }

    public function test_admin_shows_image_upload_entries(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs(DebugUsers::operator())
            ->test(ListSettings::class)
            ->assertSeeInOrder(['加入寻宠队', '联系平台'])
            ->assertSee('上传图片')
            ->assertSee('未上传');

        $rescue = Setting::query()->where('key', 'rescue_team_image')->firstOrFail();
        Livewire::actingAs(DebugUsers::operator())
            ->test(EditSetting::class, ['record' => $rescue->id])
            ->assertSee('加入寻宠队')
            ->assertSee('展示在「我的 - 加入寻宠队」');

        $contact = Setting::query()->where('key', 'contact_platform_image')->firstOrFail();
        Livewire::actingAs(DebugUsers::operator())
            ->test(EditSetting::class, ['record' => $contact->id])
            ->assertSee('联系平台')
            ->assertSee('展示在「我的 - 联系平台」');
    }
}
