<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\ContentCheck;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class WechatContentSecurity
{
    private const TOKEN_KEY = 'wechat:mini:access_token';

    public function inspectText(User $user, array $parts): string
    {
        if (! $this->enabled()) {
            return ContentCheck::PASS;
        }

        $text = trim(implode("\n", array_filter(array_map(
            fn ($part) => trim((string) $part),
            $parts,
        ), fn ($part) => $part !== '')));
        if ($text === '') {
            return ContentCheck::PASS;
        }

        $suggest = ContentCheck::PASS;
        foreach ($this->chunks($text) as $chunk) {
            $result = $this->post('wxa/msg_sec_check', [
                'openid' => $this->openid($user),
                'scene' => 4,
                'version' => 2,
                'content' => $chunk,
            ]);
            $current = $this->suggest($result);
            if ($current === ContentCheck::RISKY) {
                throw new BusinessException('内容未通过安全检测，请修改后再发');
            }
            if ($current === ContentCheck::REVIEW) {
                $suggest = ContentCheck::REVIEW;
            }
        }

        return $suggest;
    }

    public function delaysPublish(string $suggest, array $images): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $images = array_values(array_filter($images, fn ($path) => is_string($path) && $path !== ''));

        return $suggest === ContentCheck::REVIEW || $images !== [];
    }

    public function rememberTextReview(User $user, string $type, int $id): void
    {
        ContentCheck::query()->create([
            'user_id' => $user->id,
            'subject_type' => $type,
            'subject_id' => $id,
            'kind' => ContentCheck::TEXT,
            'status' => ContentCheck::REVIEW,
        ]);
    }

    public function submitImages(User $user, string $type, int $id, array $paths): void
    {
        if (! $this->enabled()) {
            return;
        }

        $paths = array_values(array_filter($paths, fn ($path) => is_string($path) && $path !== ''));
        foreach ($paths as $path) {
            $url = Storage::disk('public')->url($path);
            if (app()->environment('production') && ! str_starts_with($url, 'https://')) {
                throw new BusinessException('图片暂时无法送检');
            }
            $result = $this->post('wxa/media_check_async', [
                'openid' => $this->openid($user),
                'scene' => 4,
                'version' => 2,
                'media_url' => $url,
                'media_type' => 2,
            ]);
            $traceId = trim((string) ($result['trace_id'] ?? ''));
            if ($traceId === '') {
                throw new BusinessException('内容安全校验失败，请稍后再试');
            }
            ContentCheck::query()->create([
                'user_id' => $user->id,
                'subject_type' => $type,
                'subject_id' => $id,
                'kind' => ContentCheck::IMAGE,
                'path' => $path,
                'trace_id' => $traceId,
                'status' => ContentCheck::PENDING,
            ]);
        }
    }

    public function assertReadyForAdmin(string $type, int $id): void
    {
        if (ContentCheck::hasRisky($type, $id)) {
            throw new BusinessException('内容未通过安全检测');
        }
    }

    private function enabled(): bool
    {
        if (app()->runningUnitTests()) {
            return (bool) config('services.wechat.content_check');
        }
        if (! $this->configured()) {
            if (app()->environment('production')) {
                throw new BusinessException('内容安全暂不可用');
            }

            return false;
        }
        if (config('app.auth_disabled') && ! app()->environment('production')) {
            return false;
        }

        return true;
    }

    private function configured(): bool
    {
        return filled(config('services.wechat.mini_app_id'))
            && filled(config('services.wechat.mini_secret'));
    }

    private function openid(User $user): string
    {
        $openid = trim((string) $user->wechat_openid);
        if ($openid === '') {
            throw new BusinessException('请用微信登录后再发布');
        }

        return $openid;
    }

    private function chunks(string $text): array
    {
        $chunks = [];
        $length = mb_strlen($text);
        for ($offset = 0; $offset < $length; $offset += 2000) {
            $chunks[] = mb_substr($text, $offset, 2000);
        }

        return $chunks;
    }

    private function suggest(array $result): string
    {
        $suggest = (string) ($result['result']['suggest'] ?? '');
        if (! in_array($suggest, [ContentCheck::PASS, ContentCheck::REVIEW, ContentCheck::RISKY], true)) {
            throw new BusinessException('内容安全校验失败，请稍后再试');
        }

        return $suggest;
    }

    private function post(string $path, array $body): array
    {
        return $this->send($path, $body, true);
    }

    private function send(string $path, array $body, bool $retry): array
    {
        $token = $this->accessToken();
        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->asJson()
                ->post('https://api.weixin.qq.com/'.$path.'?access_token='.urlencode($token), $body);
        } catch (\Throwable $e) {
            report($e);
            throw new BusinessException('内容安全校验失败，请稍后再试');
        }

        $payload = $response->json();
        $code = is_array($payload) ? (int) ($payload['errcode'] ?? 0) : -1;
        if (in_array($code, [40001, 40014, 42001], true) && $retry) {
            Cache::forget(self::TOKEN_KEY);

            return $this->send($path, $body, false);
        }
        if (! is_array($payload) || ! $response->ok() || $code !== 0) {
            throw new BusinessException('内容安全校验失败，请稍后再试');
        }

        return $payload;
    }

    private function accessToken(): string
    {
        $cached = Cache::get(self::TOKEN_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::timeout(5)->acceptJson()->get('https://api.weixin.qq.com/cgi-bin/token', [
                'grant_type' => 'client_credential',
                'appid' => config('services.wechat.mini_app_id'),
                'secret' => config('services.wechat.mini_secret'),
            ]);
        } catch (\Throwable $e) {
            report($e);
            throw new BusinessException('内容安全校验失败，请稍后再试');
        }

        $token = trim((string) $response->json('access_token'));
        $ttl = (int) $response->json('expires_in') - 120;
        if (! $response->ok() || $token === '' || $ttl < 60) {
            throw new BusinessException('内容安全校验失败，请稍后再试');
        }
        Cache::put(self::TOKEN_KEY, $token, $ttl);

        return $token;
    }
}
