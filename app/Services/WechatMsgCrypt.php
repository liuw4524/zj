<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use Illuminate\Http\Request;

class WechatMsgCrypt
{
    public function verifyUrl(Request $request): string
    {
        $echo = (string) $request->query('echostr', '');
        if ($echo === '') {
            throw new BusinessException('消息推送校验失败', 403);
        }

        if ($this->encrypted($request)) {
            $this->assertSignature($request, $echo);

            return $this->decrypt($echo);
        }

        $this->assertSignature($request, null);

        return $echo;
    }

    public function readMessage(Request $request): array
    {
        $body = $this->decode($request->getContent());
        $encrypt = trim((string) ($body['Encrypt'] ?? ''));
        if ($encrypt !== '' || $this->encrypted($request)) {
            if ($encrypt === '') {
                throw new BusinessException('消息推送校验失败', 403);
            }
            $this->assertSignature($request, $encrypt);

            return $this->decode($this->decrypt($encrypt));
        }

        $this->assertSignature($request, null);

        return $body;
    }

    public function encrypt(string $plain): string
    {
        $key = $this->key();
        $packed = random_bytes(16).pack('N', strlen($plain)).$plain.$this->appId();
        $encrypted = openssl_encrypt($this->pad($packed), 'AES-256-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($key, 0, 16));
        if ($encrypted === false) {
            throw new BusinessException('消息推送校验失败', 403);
        }

        return base64_encode($encrypted);
    }

    private function encrypted(Request $request): bool
    {
        return strtolower((string) $request->query('encrypt_type', '')) === 'aes';
    }

    private function assertSignature(Request $request, ?string $encrypt): void
    {
        $token = $this->token();
        $timestamp = (string) $request->query('timestamp', '');
        $nonce = (string) $request->query('nonce', '');
        $parts = [$token, $timestamp, $nonce];
        if ($encrypt !== null) {
            $parts[] = $encrypt;
        }
        sort($parts, SORT_STRING);
        $expect = sha1(implode($parts));
        $given = (string) ($encrypt !== null
            ? $request->query('msg_signature', '')
            : $request->query('signature', ''));
        if ($timestamp === '' || $nonce === '' || ! hash_equals($expect, $given)) {
            throw new BusinessException('消息推送校验失败', 403);
        }
    }

    private function decrypt(string $encrypted): string
    {
        $key = $this->key();
        $cipher = base64_decode($encrypted, true);
        if ($cipher === false) {
            throw new BusinessException('消息推送校验失败', 403);
        }
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($key, 0, 16));
        if ($plain === false) {
            throw new BusinessException('消息推送校验失败', 403);
        }
        $plain = $this->unpad($plain);
        $content = substr($plain, 16);
        $length = unpack('N', substr($content, 0, 4))[1] ?? 0;
        $message = substr($content, 4, $length);
        $appId = substr($content, 4 + $length);
        if (! hash_equals($this->appId(), $appId)) {
            throw new BusinessException('消息推送校验失败', 403);
        }

        return $message;
    }

    private function decode(string $raw): array
    {
        $trimmed = ltrim($raw);
        if ($trimmed === '') {
            return [];
        }
        if (str_starts_with($trimmed, '<')) {
            $xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET);
            $decoded = json_decode(json_encode($xml), true);

            return is_array($decoded) ? $decoded : [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function pad(string $text): string
    {
        $amount = 32 - (strlen($text) % 32);
        if ($amount === 0) {
            $amount = 32;
        }

        return $text.str_repeat(chr($amount), $amount);
    }

    private function unpad(string $text): string
    {
        $pad = ord(substr($text, -1));
        if ($pad < 1 || $pad > 32) {
            return $text;
        }

        return substr($text, 0, -$pad);
    }

    private function token(): string
    {
        $token = trim((string) config('services.wechat.msg_token'));
        if ($token === '') {
            throw new BusinessException('消息推送校验失败', 403);
        }

        return $token;
    }

    private function key(): string
    {
        $encoded = trim((string) config('services.wechat.msg_aes_key'));
        $key = base64_decode($encoded.'=', true);
        if ($encoded === '' || $key === false || strlen($key) !== 32) {
            throw new BusinessException('消息推送校验失败', 403);
        }

        return $key;
    }

    private function appId(): string
    {
        $appId = trim((string) config('services.wechat.mini_app_id'));
        if ($appId === '') {
            throw new BusinessException('消息推送校验失败', 403);
        }

        return $appId;
    }
}
