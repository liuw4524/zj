<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CityLocationRequest;
use App\Services\GeoCityService;
use App\Support\ApiResponse;

class GeoController extends Controller
{
    public function __construct(private GeoCityService $geo) {}

    public function city(CityLocationRequest $request)
    {
        return ApiResponse::ok($this->geo->city(
            (float) $request->validated('latitude'),
            (float) $request->validated('longitude'),
        ));
    }
}
