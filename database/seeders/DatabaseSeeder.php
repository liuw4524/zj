<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\DebugUsers;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make('password');

        User::query()->updateOrCreate(
            ['email' => DebugUsers::OWNER],
            ['name' => '调试宠主', 'phone' => '13800000001', 'password' => $password, 'is_blocked' => false],
        );
        User::query()->updateOrCreate(
            ['email' => DebugUsers::CLUE],
            ['name' => '调试线索人', 'phone' => '13800000002', 'password' => $password, 'is_blocked' => false],
        );
        $ops = User::query()->updateOrCreate(
            ['email' => DebugUsers::OPS],
            ['name' => '调试运营', 'phone' => '13800000003', 'password' => $password, 'is_blocked' => false],
        );
        $ops->is_admin = true;
        $ops->save();

        if (! app()->runningUnitTests()) {
            $this->call(DebugCluePostSeeder::class);
            $this->call(DebugLeadSeeder::class);
        }
    }
}
