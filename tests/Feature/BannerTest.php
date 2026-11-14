<?php

namespace Tests\Feature;

use App\Models\Banner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
    }

    public function test_home_banners_are_visible_images_in_sort_order(): void
    {
        Storage::disk('public')->put('banners/later.jpg', 'later');
        Storage::disk('public')->put('banners/first.jpg', 'first');
        Storage::disk('public')->put('banners/hidden.jpg', 'hidden');

        Banner::query()->create(['path' => 'banners/later.jpg', 'sort' => 2, 'is_visible' => true]);
        Banner::query()->create(['path' => 'banners/first.jpg', 'sort' => 1, 'is_visible' => true]);
        Banner::query()->create(['path' => 'banners/hidden.jpg', 'sort' => 0, 'is_visible' => false]);

        $this->getJson('/api/banners')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.url', Storage::disk('public')->url('banners/first.jpg'))
            ->assertJsonPath('data.1.url', Storage::disk('public')->url('banners/later.jpg'));
    }
}
