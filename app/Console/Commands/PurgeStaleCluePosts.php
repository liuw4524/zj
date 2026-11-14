<?php

namespace App\Console\Commands;

use App\Services\CluePostService;
use Illuminate\Console\Command;

class PurgeStaleCluePosts extends Command
{
    protected $signature = 'clue-posts:purge-stale';

    protected $description = '删除下架后连续 30 天未再点亮的公益线索帖和图片';

    public function handle(CluePostService $clues): int
    {
        $count = $clues->purgeStale();
        $this->info('删除 '.$count.' 条线索帖');

        return self::SUCCESS;
    }
}
