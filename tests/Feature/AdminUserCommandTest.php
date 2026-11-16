<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_admin_who_can_open_panel(): void
    {
        $this->artisan('make:filament-user', [
            '--name' => '超级管理员',
            '--email' => 'admin@example.com',
            '--password' => 'password123',
        ])->assertSuccessful();

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->assertTrue($admin->is_admin);
        $this->assertTrue(Hash::check('password123', $admin->password));
        $this->assertTrue($admin->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_command_updates_existing_email_and_rejects_short_password(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);
        $user->is_admin = false;
        $user->save();

        $this->artisan('make:filament-user', [
            '--email' => 'admin@example.com',
            '--password' => 'newpassword1',
        ])->assertSuccessful();

        $admin = $user->fresh();
        $this->assertTrue($admin->is_admin);
        $this->assertTrue(Hash::check('newpassword1', $admin->password));

        $this->artisan('make:filament-user', [
            '--email' => 'other@example.com',
            '--password' => 'short',
        ])->assertFailed();

        $this->assertNull(User::query()->where('email', 'other@example.com')->first());
    }

    public function test_plain_user_cannot_access_panel(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));
    }
}
