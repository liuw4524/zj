<?php

namespace Tests\Feature;

use App\Enums\SupportLogSource;
use App\Filament\Resources\SupportOrderResource\Pages\ListSupportOrders;
use App\Filament\Resources\SupportOrderResource\Pages\ViewSupportOrder;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\EscrowOrder;
use App\Models\Setting;
use App\Models\SupportOrder;
use App\Models\User;
use App\Support\DebugUsers;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SupportPawTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_paid_support_adds_paws_without_growth_or_escrow(): void
    {
        $this->give(5, 'token-five-01')->assertJsonPath('data.amount', 500);

        $this->asRole('owner')->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.supporter.paws', 5)
            ->assertJsonPath('data.supporter.title', '支持新爪')
            ->assertJsonPath('data.medal.points', 0)
            ->assertJsonPath('data.medal.level', 1);

        $this->assertSame(0, EscrowOrder::query()->count());

        $this->give(25, 'token-warm-01');
        $this->asRole('owner')->getJson('/api/me')
            ->assertJsonPath('data.supporter.paws', 30)
            ->assertJsonPath('data.supporter.title', '热心爪')
            ->assertJsonPath('data.medal.points', 0);

        $this->give(70, 'token-guard-1');
        $this->asRole('owner')->getJson('/api/me')
            ->assertJsonPath('data.supporter.title', '守护爪');

        $this->give(400, 'token-legend1');
        $owner = User::query()->where('email', DebugUsers::OWNER)->firstOrFail();
        $this->assertSame(500, $owner->support_paws);
        $this->assertSame(0, $owner->growth_points);
        $this->assertSame(1, (int) $owner->medal_level);
        $this->asRole('owner')->getJson('/api/me')
            ->assertJsonPath('data.supporter.title', '传奇爪');
    }

    public function test_custom_count_must_be_positive_integer(): void
    {
        $this->asRole('owner')->postJson('/api/support/orders', [
            'paw_count' => 1.5,
            'anonymous' => false,
            'client_token' => 'token-decimal',
        ])->assertStatus(422)->assertJsonPath('message', '爪印数须为整数');

        $this->asRole('owner')->postJson('/api/support/orders', [
            'paw_count' => 0,
            'anonymous' => false,
            'client_token' => 'token-zero-01',
        ])->assertStatus(422)->assertJsonPath('message', '最少 1 爪印');

        $this->assertSame(0, SupportOrder::query()->count());
    }

    public function test_repeat_token_does_not_double_charge(): void
    {
        $first = $this->give(2, 'same-token-1')->json('data.id');
        $this->give(9, 'same-token-1')
            ->assertJsonPath('data.id', $first)
            ->assertJsonPath('data.paw_count', 2);

        $this->assertSame(1, SupportOrder::query()->count());
        $this->assertSame(2, User::query()->where('email', DebugUsers::OWNER)->value('support_paws'));
    }

    public function test_wall_hides_name_and_can_be_turned_off(): void
    {
        $this->give(1, 'wall-anon-01', true);
        $this->give(2, 'wall-name-01', false);

        $this->asRole('clue')->getJson('/api/support')
            ->assertOk()
            ->assertJsonPath('data.wall_enabled', true)
            ->assertJsonPath('data.wall.0.name', '调试宠主')
            ->assertJsonPath('data.wall.0.paws', 2)
            ->assertJsonPath('data.wall.1.name', '匿名');

        Setting::query()->where('key', 'support_wall_enabled')->update(['value' => '0']);

        $this->asRole('clue')->getJson('/api/support')
            ->assertOk()
            ->assertJsonPath('data.wall_enabled', false)
            ->assertJsonPath('data.wall', []);
    }

    public function test_unbound_phone_can_pay_and_hides_other_users_orders(): void
    {
        $paid = $this->give(1, 'owner-only-1')->json('data.id');

        $this->asRole('clue')->getJson('/api/support/orders/'.$paid)->assertStatus(404);

        User::query()->where('email', DebugUsers::OWNER)->update(['phone' => null]);

        $this->asRole('owner')->postJson('/api/support/orders', [
            'paw_count' => 1,
            'anonymous' => false,
            'client_token' => 'no-phone-01',
        ])->assertOk();

        $this->assertSame(2, SupportOrder::query()->count());
    }

    public function test_admin_order_list_shows_paid_support(): void
    {
        $this->give(3, 'list-order-1', true);

        Livewire::actingAs(DebugUsers::operator())
            ->test(ListSupportOrders::class)
            ->assertCanSeeTableRecords(SupportOrder::query()->get());
    }

    public function test_admin_support_detail_shows_balance_and_today(): void
    {
        $this->give(5, 'detail-order-1');
        $order = SupportOrder::query()->where('client_token', 'detail-order-1')->firstOrFail();

        Livewire::actingAs(DebugUsers::operator())
            ->test(ListSupportOrders::class)
            ->assertSee('今日 1 笔，5.00 元')
            ->assertSee('13800000001')
            ->assertSee('详情')
            ->filterTable('anonymous', true)
            ->assertCanNotSeeTableRecords([$order]);

        Livewire::actingAs(DebugUsers::operator())
            ->test(ViewSupportOrder::class, ['record' => $order->id])
            ->assertSee('调试宠主')
            ->assertSee('13800000001')
            ->assertSee('5.00 元')
            ->assertSee('入账后累计爪印')
            ->assertSee('支持新爪')
            ->assertSee('赠送');
    }

    public function test_admin_correction_is_logged_and_skips_growth(): void
    {
        $this->give(5, 'before-fix-1');
        $owner = User::query()->where('email', DebugUsers::OWNER)->firstOrFail();

        Livewire::actingAs(DebugUsers::operator())
            ->test(ListUsers::class)
            ->callTableAction('adjustSupport', $owner, data: [
                'delta' => -4,
                'note' => '多记一笔',
            ]);

        $owner->refresh();
        $this->assertSame(1, $owner->support_paws);
        $this->assertSame(0, $owner->growth_points);
        $this->assertSame(1, (int) $owner->medal_level);
        $this->assertDatabaseHas('support_logs', [
            'user_id' => $owner->id,
            'paws' => -4,
            'balance_after' => 1,
            'source' => SupportLogSource::Correction->value,
            'note' => '多记一笔',
            'operator_id' => DebugUsers::operator()->id,
        ]);

        Livewire::actingAs(DebugUsers::operator())
            ->test(ListUsers::class)
            ->callTableAction('adjustSupport', $owner, data: [
                'delta' => -1,
                'note' => '减到零',
            ]);

        $owner->refresh();
        $this->assertSame(0, $owner->support_paws);

        Livewire::actingAs(DebugUsers::operator())
            ->test(ListUsers::class)
            ->callTableAction('adjustSupport', $owner, data: [
                'delta' => -1,
                'note' => '再减',
            ])
            ->assertHasErrors(['delta']);
    }

    private function give(int $paws, string $token, bool $anonymous = false)
    {
        return $this->asRole('owner')->postJson('/api/support/orders', [
            'paw_count' => $paws,
            'anonymous' => $anonymous,
            'client_token' => $token,
        ])->assertOk();
    }

    private function asRole(string $role): static
    {
        return $this->withHeader('X-Debug-User', $role);
    }
}
