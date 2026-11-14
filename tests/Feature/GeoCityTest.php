<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeoCityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_reverse_geocode_returns_province_and_city(): void
    {
        config(['services.tencent_map.key' => 'test-key']);
        Http::fake([
            'apis.map.qq.com/*' => Http::response([
                'status' => 0,
                'result' => [
                    'ad_info' => [
                        'province' => '北京市',
                        'city' => '北京市',
                        'district' => '东城区',
                    ],
                ],
            ]),
        ]);

        $this->getJson('/api/geo/city?latitude=39.9&longitude=116.4')
            ->assertOk()
            ->assertJsonPath('data.province', '北京市')
            ->assertJsonPath('data.city', '北京市')
            ->assertJsonMissingPath('data.district');
    }

    public function test_missing_map_key_does_not_guess_a_city(): void
    {
        config(['services.tencent_map.key' => '']);

        $this->getJson('/api/geo/city?latitude=39.9&longitude=116.4')
            ->assertStatus(422)
            ->assertJsonPath('message', '暂时无法定位城市');
    }
}
