<?php

namespace App\Console\Commands;

use App\Enums\PostStatus;
use App\Models\Post;
use Illuminate\Console\Command;

class FreezeExpiredPosts extends Command
{
    protected $signature = 'posts:freeze-expired';

    protected $description = '把点亮到期的展示中帖子改为已下架';

    public function handle(): int
    {
        $count = Post::query()
            ->where('status', PostStatus::Showing)
            ->whereNotNull('lit_until')
            ->where('lit_until', '<=', now())
            ->update(['status' => PostStatus::Frozen->value]);

        $this->info('冻结 '.$count.' 条');

        return self::SUCCESS;
    }
}
