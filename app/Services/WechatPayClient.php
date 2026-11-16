<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WechatPayClient
{
    public static function certificatesAvailable(): bool
    {
        foreach (['private_key', 'cert', 'public_key'] as $key) {
            $path = trim((string) config('services.wechat.'.$key));
            if ($path === '' || ! self::fileExists($path)) {
                return false;
            }
            if (! self::fileReadable($path)) {
                throw new BusinessException('微信支付证书无法读取');
            }
        }

        return true;
    }

    public function jsapi(string $outTradeNo, int $amount, string $description, User $user): array
    {
        $openid = trim((string) $user->wechat_openid);
        if ($openid === '') {
            throw new BusinessException('请用微信登录后再支付');
        }

        $payload = $this->request('POST', '/v3/pay/transactions/jsapi', [
            'appid' => $this->appId(),
            'mchid' => $this->mchId(),
            'description' => $description,
            'out_trade_no' => $outTradeNo,
            'notify_url' => $this->notifyUrl(),
            'amount' => [
                'total' => $amount,
                'currency' => 'CNY',
            ],
            'payer' => [
                'openid' => $openid,
            ],
        ]);
        $prepayId = trim((string) ($payload['prepay_id'] ?? ''));
        if ($prepayId === '') {
            throw new BusinessException('微信支付下单失败');
        }

        return $this->miniPayParams($prepayId);
    }

    public function refund(string $outTradeNo, string $outRefundNo, int $refund, int $total): string
    {
        $payload = $this->request('POST', '/v3/refund/domestic/refunds', [
            'out_trade_no' => $outTradeNo,
            'out_refund_no' => $outRefundNo,
            'notify_url' => $this->notifyUrl(),
            'amount' => [
                'refund' => $refund,
                'total' => $total,
                'currency' => 'CNY',
            ],
        ]);
        $status = (string) ($payload['status'] ?? '');
        if (! in_array($status, ['SUCCESS', 'PROCESSING'], true)) {
            throw new BusinessException('微信退款失败');
        }

        return $status;
    }

    public function decryptNotify(Request $request): array
    {
        $body = $request->getContent();
        $timestamp = (string) $request->header('Wechatpay-Timestamp', '');
        $nonce = (string) $request->header('Wechatpay-Nonce', '');
        $signature = (string) $request->header('Wechatpay-Signature', '');
        $serial = (string) $request->header('Wechatpay-Serial', '');
        if ($timestamp === '' || $nonce === '' || $signature === '' || abs(time() - (int) $timestamp) > 300) {
            throw new BusinessException('微信支付通知无效');
        }
        if (! hash_equals($this->publicKeyId(), $serial)) {
            throw new BusinessException('微信支付通知无效');
        }
        $message = $timestamp."\n".$nonce."\n".$body."\n";
        $verified = openssl_verify($message, base64_decode($signature), $this->publicKey(), OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            throw new BusinessException('微信支付通知无效');
        }

        $outer = json_decode($body, true);
        $resource = is_array($outer) ? ($outer['resource'] ?? null) : null;
        if (! is_array($resource)) {
            throw new BusinessException('微信支付通知无效');
        }
        $plain = $this->decryptResource($resource);
        $decoded = json_decode($plain, true);
        if (! is_array($decoded)) {
            throw new BusinessException('微信支付通知无效');
        }

        return [
            'event_type' => (string) ($outer['event_type'] ?? ''),
            'resource' => $decoded,
        ];
    }

    private function miniPayParams(string $prepayId): array
    {
        $timestamp = (string) time();
        $nonce = Str::random(32);
        $package = 'prepay_id='.$prepayId;
        $message = $this->appId()."\n".$timestamp."\n".$nonce."\n".$package."\n";

        return [
            'timeStamp' => $timestamp,
            'nonceStr' => $nonce,
            'package' => $package,
            'signType' => 'RSA',
            'paySign' => $this->sign($message),
        ];
    }

    private function request(string $method, string $path, array $body): array
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new BusinessException('微信支付下单失败');
        }
        $timestamp = (string) time();
        $nonce = Str::random(32);
        $message = $method."\n".$path."\n".$timestamp."\n".$nonce."\n".$json."\n";
        $authorization = sprintf(
            'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",signature="%s",timestamp="%s",serial_no="%s"',
            $this->mchId(),
            $nonce,
            $this->sign($message),
            $timestamp,
            $this->merchantSerial(),
        );
        try {
            $response = Http::withHeaders([
                'Authorization' => $authorization,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => 'zj-pay',
            ])->timeout(10)->withBody($json, 'application/json')->send($method, 'https://api.mch.weixin.qq.com'.$path);
        } catch (BusinessException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            throw new BusinessException('微信支付请求失败');
        }

        $payload = $response->json();
        if (! is_array($payload) || ! $response->successful()) {
            $message = is_array($payload) ? trim((string) ($payload['message'] ?? '')) : '';
            throw new BusinessException($message !== '' ? $message : '微信支付请求失败');
        }
        $this->verifyResponse($response);

        return $payload;
    }

    private function verifyResponse(Response $response): void
    {
        $timestamp = (string) $response->header('Wechatpay-Timestamp');
        $nonce = (string) $response->header('Wechatpay-Nonce');
        $signature = (string) $response->header('Wechatpay-Signature');
        $serial = (string) $response->header('Wechatpay-Serial');
        if ($timestamp === '' || $nonce === '' || $signature === '') {
            throw new BusinessException('微信支付验签失败');
        }
        if (! hash_equals($this->publicKeyId(), $serial)) {
            throw new BusinessException('微信支付验签失败');
        }
        $message = $timestamp."\n".$nonce."\n".$response->body()."\n";
        $verified = openssl_verify($message, base64_decode($signature), $this->publicKey(), OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            throw new BusinessException('微信支付验签失败');
        }
    }

    private function decryptResource(array $resource): string
    {
        $ciphertext = base64_decode((string) ($resource['ciphertext'] ?? ''), true);
        $nonce = (string) ($resource['nonce'] ?? '');
        if ($ciphertext === false || strlen($ciphertext) <= 16 || $nonce === '') {
            throw new BusinessException('微信支付通知无效');
        }
        $plain = openssl_decrypt(
            substr($ciphertext, 0, -16),
            'aes-256-gcm',
            $this->apiV3Key(),
            OPENSSL_RAW_DATA,
            $nonce,
            substr($ciphertext, -16),
            (string) ($resource['associated_data'] ?? ''),
        );
        if (! is_string($plain) || $plain === '') {
            throw new BusinessException('微信支付通知无效');
        }

        return $plain;
    }

    private function sign(string $message): string
    {
        $signed = '';
        if (! openssl_sign($message, $signed, $this->privateKey(), OPENSSL_ALGO_SHA256)) {
            throw new BusinessException('微信支付签名失败');
        }

        return base64_encode($signed);
    }

    private function privateKey()
    {
        try {
            $key = openssl_pkey_get_private($this->contents('private_key'));
        } catch (BusinessException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            throw new BusinessException('微信支付密钥无效');
        }
        if ($key === false) {
            throw new BusinessException('微信支付密钥无效');
        }

        return $key;
    }

    private function publicKey()
    {
        try {
            $key = openssl_pkey_get_public($this->contents('public_key'));
        } catch (BusinessException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            throw new BusinessException('微信支付公钥无效');
        }
        if ($key === false) {
            throw new BusinessException('微信支付公钥无效');
        }

        return $key;
    }

    private function merchantSerial(): string
    {
        try {
            $cert = openssl_x509_read($this->contents('cert'));
        } catch (BusinessException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            throw new BusinessException('微信支付证书无效');
        }
        $parsed = $cert ? openssl_x509_parse($cert) : false;
        $serial = strtoupper((string) ($parsed['serialNumberHex'] ?? ''));
        if ($serial === '') {
            throw new BusinessException('微信支付证书无效');
        }

        return $serial;
    }

    private function contents(string $key): string
    {
        try {
            $body = file_get_contents($this->path($key));
        } catch (BusinessException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            throw new BusinessException('微信支付证书无法读取');
        }
        if (! is_string($body) || $body === '') {
            throw new BusinessException('微信支付证书无法读取');
        }

        return $body;
    }

    private function path(string $key): string
    {
        $path = trim((string) config('services.wechat.'.$key));
        if ($path === '' || ! self::fileExists($path)) {
            throw new BusinessException('微信支付未配置');
        }
        if (! self::fileReadable($path)) {
            throw new BusinessException('微信支付证书无法读取');
        }

        return $path;
    }

    private static function fileExists(string $path): bool
    {
        try {
            return is_file($path);
        } catch (\Throwable $e) {
            report($e);
            throw new BusinessException('微信支付证书无法读取');
        }
    }

    private static function fileReadable(string $path): bool
    {
        try {
            return is_readable($path);
        } catch (\Throwable $e) {
            report($e);
            throw new BusinessException('微信支付证书无法读取');
        }
    }

    private function appId(): string
    {
        return $this->required('mini_app_id');
    }

    private function mchId(): string
    {
        return $this->required('mch_id');
    }

    private function apiV3Key(): string
    {
        $key = $this->required('api_v3_key');
        if (strlen($key) !== 32) {
            throw new BusinessException('微信支付未配置');
        }

        return $key;
    }

    private function publicKeyId(): string
    {
        return $this->required('public_key_id');
    }

    private function notifyUrl(): string
    {
        return $this->required('notify_url');
    }

    private function required(string $key): string
    {
        $value = trim((string) config('services.wechat.'.$key));
        if ($value === '') {
            throw new BusinessException('微信支付未配置');
        }

        return $value;
    }
}
