<?php

namespace App\Console\Commands;

use App\Services\WechatPayClient;
use App\Services\WechatPayService;
use Illuminate\Console\Command;

class SyncPendingWechatPayments extends Command
{
    protected $signature = 'pay:sync-pending';

    protected $description = '向微信核对尚未入账的支付单';

    public function handle(WechatPayService $service, WechatPayClient $client): int
    {
        $count = $service->syncOpen($client);
        $this->info('入账 '.$count.' 条');

        return self::SUCCESS;
    }
}
