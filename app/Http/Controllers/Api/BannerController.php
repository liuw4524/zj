<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\Storage;

class BannerController extends Controller
{
    public function index()
    {
        $banners = Banner::query()
            ->where('is_visible', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn (Banner $banner) => [
                'url' => Storage::disk('public')->url($banner->path),
            ])
            ->values();

        return ApiResponse::ok($banners);
    }
}
