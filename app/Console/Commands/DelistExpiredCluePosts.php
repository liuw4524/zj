<?php

namespace App\Console\Commands;

use App\Services\CluePostService;
use Illuminate\Console\Command;

class DelistExpiredCluePosts extends Command
{
    protected $signature = 'clue-posts:delist-expired';

    protected $description = '把点亮到期的公益线索帖改为已下架';

    public function handle(CluePostService $clues): int
    {
        $count = $clues->delistExpired();
        $this->info('下架 '.$count.' 条线索帖');

        return self::SUCCESS;
    }
}
