<?php

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Services\WechatPayClient;
use Tests\TestCase;

class WechatCertificateTest extends TestCase
{
    public function test_missing_certificate_is_not_ready(): void
    {
        config([
            'services.wechat.private_key' => storage_path('framework/missing-wechat-key.pem'),
            'services.wechat.cert' => storage_path('framework/missing-wechat-cert.pem'),
            'services.wechat.public_key' => storage_path('framework/missing-wechat-public.pem'),
        ]);

        $this->assertFalse(WechatPayClient::certificatesAvailable());
    }

    public function test_unreadable_certificate_is_a_business_error(): void
    {
        $path = storage_path('framework/unreadable-wechat-key.pem');
        file_put_contents($path, 'secret');
        chmod($path, 0000);

        config([
            'services.wechat.private_key' => $path,
            'services.wechat.cert' => $path,
            'services.wechat.public_key' => $path,
        ]);

        try {
            WechatPayClient::certificatesAvailable();
            $this->fail('证书不可读时应拒绝');
        } catch (BusinessException $e) {
            $this->assertSame('微信支付证书无法读取', $e->getMessage());
        } finally {
            chmod($path, 0644);
            unlink($path);
        }
    }
}
