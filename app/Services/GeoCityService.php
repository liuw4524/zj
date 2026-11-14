<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use Illuminate\Support\Facades\Http;

class GeoCityService
{
    public function city(float $latitude, float $longitude): array
    {
        $key = (string) config('services.tencent_map.key');
        if ($key === '') {
            throw new BusinessException('暂时无法定位城市');
        }

        $response = Http::timeout(5)->get('https://apis.map.qq.com/ws/geocoder/v1/', [
            'location' => $latitude.','.$longitude,
            'key' => $key,
        ]);

        $info = $response->json('result.ad_info') ?? [];
        $province = trim((string) ($info['province'] ?? ''));
        $city = trim((string) ($info['city'] ?? ''));

        if (! $response->ok() || (int) $response->json('status') !== 0 || $province === '' || $city === '') {
            throw new BusinessException('暂时无法定位城市');
        }

        return [
            'province' => $province,
            'city' => $city,
        ];
    }
}
