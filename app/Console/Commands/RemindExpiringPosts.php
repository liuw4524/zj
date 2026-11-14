<?php

namespace App\Console\Commands;

use App\Enums\PostStatus;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Setting;
use Illuminate\Console\Command;

class RemindExpiringPosts extends Command
{
    protected $signature = 'posts:remind-expiring';

    protected $description = '点亮到期前写入站内提醒';

    public function handle(): int
    {
        $days = max(1, Setting::getInt('remind_days_before'));
        $posts = Post::query()
            ->where('status', PostStatus::Showing)
            ->whereNotNull('lit_until')
            ->where('lit_until', '>', now())
            ->where('lit_until', '<=', now()->addDays($days))
            ->get();

        $created = 0;
        foreach ($posts as $post) {
            $key = 'light:'.$post->id.':'.$post->lit_until->getTimestamp();
            $notification = Notification::query()->firstOrCreate(
                ['dedupe_key' => $key],
                [
                    'user_id' => $post->user_id,
                    'post_id' => $post->id,
                    'kind' => 'light_expiring',
                    'title' => '点亮即将到期',
                    'body' => $post->pet_name.' 的寻宠将在 '.$post->lit_until->format('m月d日 H:i').' 到期，请及时再点亮。',
                ],
            );
            if ($notification->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->info('新增提醒 '.$created.' 条');

        return self::SUCCESS;
    }
}
