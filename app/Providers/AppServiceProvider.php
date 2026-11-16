<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Services\MockPaymentGateway;
use App\Services\WechatPayClient;
use App\Services\WechatPaymentGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, function ($app) {
            if ($app->runningUnitTests() || ! $this->wechatPayReady()) {
                return $app->make(MockPaymentGateway::class);
            }

            return $app->make(WechatPaymentGateway::class);
        });
    }

    private function wechatPayReady(): bool
    {
        $config = config('services.wechat');

        return filled($config['mch_id'] ?? null)
            && filled($config['api_v3_key'] ?? null)
            && filled($config['mini_app_id'] ?? null)
            && WechatPayClient::certificatesAvailable();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
