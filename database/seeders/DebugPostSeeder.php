<?php

namespace Database\Seeders;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Support\DebugUsers;
use App\Support\TraceCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class DebugPostSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->where('email', DebugUsers::CLUE)->first();
        if (! $user) {
            return;
        }

        $sources = $this->photoSources($user->id);

        $samples = [
            [
                'pet_name' => '假帖小白',
                'breed' => '柯基',
                'sex' => '公',
                'description' => '朝阳公园北门走失，白毛短腿',
                'pet_traits' => '短腿，会回应小白',
                'lost_place' => '朝阳公园北门',
                'reward_amount' => 0,
                'lost_at' => now()->subHours(6),
            ],
            [
                'pet_name' => '假帖橘子',
                'breed' => '橘猫',
                'sex' => '母',
                'description' => '国贸附近走失，脖子有铃铛',
                'pet_traits' => '脖子有铃铛',
                'lost_place' => '国贸附近',
                'reward_amount' => 5000,
                'lost_at' => now()->subHours(20),
            ],
            [
                'pet_name' => '假帖煤球',
                'breed' => '田园犬',
                'sex' => '未知',
                'description' => '三里屯走失，黑色卷毛',
                'pet_traits' => '黑色卷毛',
                'lost_place' => '三里屯',
                'reward_amount' => 70000,
                'lost_at' => now()->subDays(2),
            ],
        ];

        foreach ($samples as $index => $sample) {
            $post = Post::query()->where('pet_name', $sample['pet_name'])->first() ?? new Post;
            $post->user_id = $user->id;
            $post->pet_name = $sample['pet_name'];
            $post->breed = $sample['breed'];
            $post->sex = $sample['sex'];
            $post->description = $sample['description'];
            $post->pet_traits = $sample['pet_traits'];
            $post->province = '北京市';
            $post->city = '北京市';
            $post->district = '朝阳区';
            $post->town = null;
            $post->lost_place = $sample['lost_place'];
            $post->lost_story = '出门后未归';
            $post->lost_at = $sample['lost_at'];
            $post->reward_amount = $sample['reward_amount'];
            $post->escrow_to_platform = false;
            $post->contact_name = $user->name ?: '调试线索人';
            $post->contact_phone = $user->phone ?: '13800000002';
            $post->status = PostStatus::Showing;
            $post->lit_until = now()->addDays(Setting::getInt('light_days'));
            $post->active_key = $user->id.'-demo-'.($index + 1);
            $post->reject_reason = null;
            if (! $post->trace_code) {
                $post->trace_code = $this->unusedTraceCode();
            }
            $post->save();

            $cover = $post->images()->first();
            $coverTooSmall = $cover && Storage::disk('public')->size($cover->path) < 20000;
            if ($cover && ! $coverTooSmall) {
                continue;
            }
            if ($sources->isEmpty()) {
                continue;
            }
            if ($coverTooSmall) {
                Storage::disk('public')->delete($cover->path);
                $cover->delete();
            }

            $source = $sources[$index % $sources->count()];
            $extension = pathinfo($source, PATHINFO_EXTENSION) ?: 'jpg';
            $path = 'uploads/'.$user->id.'/debug-post-'.($index + 1).'.'.$extension;
            if (! Storage::disk('public')->exists($path)) {
                Storage::disk('public')->copy($source, $path);
            }
            $post->images()->create([
                'path' => $path,
                'sort' => 0,
            ]);
        }
    }

    private function photoSources(int $userId)
    {
        return collect(Storage::disk('public')->files('uploads/'.$userId))
            ->reject(fn (string $path) => str_contains($path, 'debug-'))
            ->filter(fn (string $path) => Storage::disk('public')->size($path) >= 20000)
            ->values();
    }

    private function unusedTraceCode(): string
    {
        do {
            $code = TraceCode::make();
        } while (Post::query()->where('trace_code', $code)->exists());

        return $code;
    }
}
