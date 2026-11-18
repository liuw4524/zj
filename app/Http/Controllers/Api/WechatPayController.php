<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\WechatPayClient;
use App\Services\WechatPayService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Throwable;

class WechatPayController extends Controller
{
    public function notify(Request $request, WechatPayClient $client, WechatPayService $service)
    {
        try {
            $service->handle($client->decryptNotify($request));
        } catch (Throwable $e) {
            report($e);

            return response()->json(['code' => 'FAIL', 'message' => '失败'], 500);
        }

        return response()->json(['code' => 'SUCCESS', 'message' => '成功']);
    }

    public function confirm(Request $request, WechatPayClient $client, WechatPayService $service)
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new BusinessException('请用微信登录后再支付', 401);
        }
        $data = $request->validate([
            'out_trade_no' => ['required', 'string', 'max:32'],
        ]);
        $service->confirm($user, $data['out_trade_no'], $client);

        return ApiResponse::ok();
    }
}
