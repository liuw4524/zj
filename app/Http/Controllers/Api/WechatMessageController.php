<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Services\ContentCheckSettler;
use App\Services\WechatMsgCrypt;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WechatMessageController extends Controller
{
    public function message(Request $request, WechatMsgCrypt $crypt, ContentCheckSettler $settler): Response
    {
        try {
            if ($request->isMethod('GET')) {
                return $this->plain($crypt->verifyUrl($request));
            }

            $payload = $crypt->readMessage($request);
            if (($payload['Event'] ?? '') === 'wxa_media_check') {
                $traceId = trim((string) ($payload['trace_id'] ?? ''));
                if ($traceId !== '') {
                    $suggest = (int) ($payload['errcode'] ?? 0) === 0
                        ? (string) ($payload['result']['suggest'] ?? 'review')
                        : 'review';
                    $label = isset($payload['result']['label']) ? (int) $payload['result']['label'] : null;
                    $settler->apply($traceId, $suggest, $label);
                }
            }
        } catch (BusinessException $e) {
            return $this->plain('fail', $e->status >= 400 ? $e->status : 500);
        }

        return $this->plain('success');
    }

    private function plain(string $body, int $status = 200): Response
    {
        return response($body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
