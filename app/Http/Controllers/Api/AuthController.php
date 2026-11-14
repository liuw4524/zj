<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\WechatLoginRequest;
use App\Services\GrowthService;
use App\Services\ProfileService;
use App\Services\WechatAuthService;
use App\Support\ApiResponse;

class AuthController extends Controller
{
    public function __construct(
        private WechatAuthService $wechat,
        private ProfileService $profiles,
        private GrowthService $growth,
    ) {}

    public function wechat(WechatLoginRequest $request)
    {
        $result = $this->wechat->login($request->validated('code'));

        return ApiResponse::ok([
            'token' => $result['token'],
            'user' => $this->profiles->payload($result['user'], $this->growth->summary($result['user'])),
        ]);
    }
}
