<?php

namespace Tests\Feature;

use App\Enums\EscrowStatus;
use App\Enums\PostStatus;
use App\Enums\SupportOrderStatus;
use App\Filament\Resources\EscrowOrderResource\Pages\ListEscrowOrders;
use App\Filament\Resources\PostResource\Pages\ListPosts;
use App\Filament\Resources\PostResource\Pages\ViewPost;
use App\Filament\Resources\SettingResource\Pages\EditSetting;
use App\Filament\Resources\SettingResource\Pages\ListSettings;
use App\Filament\Resources\SupportOrderResource\Pages\ListSupportOrders;
use App\Models\EscrowOrder;
use App\Models\Post;
use App\Models\Setting;
use App\Models\SupportOrder;
use App\Support\DebugUsers;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminMoneyUnitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_lists_show_yuan_and_cap_is_saved_as_fen(): void
    {
        $owner = DebugUsers::resolve('owner');
        $post = new Post;
        $post->forceFill([
            'user_id' => $owner->id,
            'pet_name' => '元宝',
            'description' => '金额展示',
            'city' => '北海市',
            'lost_at' => now(),
            'reward_amount' => 123456,
            'contact_name' => '宠主',
            'contact_phone' => '13800000001',
            'status' => PostStatus::Showing,
        ])->save();

        (new EscrowOrder)->forceFill([
            'post_id' => $post->id,
            'user_id' => $owner->id,
            'amount' => 123456,
            'status' => EscrowStatus::Held,
            'out_trade_no' => 'money-unit-escrow',
            'commission_amount' => 18518,
            'payee_amount' => 104938,
        ])->save();

        (new SupportOrder)->forceFill([
            'user_id' => $owner->id,
            'paw_count' => 3,
            'amount' => 300,
            'anonymous' => false,
            'status' => SupportOrderStatus::Paid,
            'out_trade_no' => 'money-unit-support',
            'client_token' => 'money-unit-support',
        ])->save();

        Livewire::actingAs(DebugUsers::operator())
            ->test(ListPosts::class)
            ->assertSee('1,234.56 元');

        Livewire::actingAs(DebugUsers::operator())
            ->test(ViewPost::class, ['record' => $post->id])
            ->assertSee('1,234.56 元');

        Livewire::actingAs(DebugUsers::operator())
            ->test(ListEscrowOrders::class)
            ->set('activeTab', 'all')
            ->assertSee('1,234.56 元')
            ->assertSee('185.18 元')
            ->assertSee('1,049.38 元');

        Livewire::actingAs(DebugUsers::operator())
            ->test(ListSupportOrders::class)
            ->assertSee('3.00 元');

        Livewire::actingAs(DebugUsers::operator())
            ->test(ListSettings::class)
            ->assertSee('酬谢上限（元）')
            ->assertSee('6,000.00 元');

        $setting = Setting::query()->where('key', 'reward_cap_fen')->firstOrFail();

        Livewire::actingAs(DebugUsers::operator())
            ->test(EditSetting::class, ['record' => $setting->id])
            ->assertFormSet(['value' => 6000])
            ->fillForm(['value' => '100.5'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('10050', $setting->refresh()->value);

        Livewire::actingAs(DebugUsers::operator())
            ->test(EditSetting::class, ['record' => $setting->id])
            ->fillForm(['value' => '100.555'])
            ->call('save')
            ->assertHasFormErrors(['value']);

        $this->assertSame('10050', $setting->refresh()->value);
    }
}
