<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WechatPayClient;
use App\Services\WechatPayService;
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
}
