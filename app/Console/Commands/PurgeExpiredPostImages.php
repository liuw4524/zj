<?php

namespace App\Console\Commands;

use App\Services\PostService;
use Illuminate\Console\Command;

class PurgeExpiredPostImages extends Command
{
    protected $signature = 'posts:purge-images';

    protected $description = '删除结案、撤回或驳回满 180 天且资金已结束的丢失帖图片';

    public function handle(PostService $posts): int
    {
        $count = $posts->purgeExpiredImages();
        $this->info('清理 '.$count.' 条丢失帖的图片');

        return self::SUCCESS;
    }
}
