<?php

namespace Database\Seeders;

use App\Enums\CluePostStatus;
use App\Models\CluePost;
use App\Models\Setting;
use App\Models\User;
use App\Support\DebugUsers;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class DebugCluePostSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->where('email', DebugUsers::CLUE)->first();
        if (! $user) {
            return;
        }

        $sources = collect(Storage::disk('public')->files('uploads/'.$user->id))
            ->reject(fn (string $path) => str_contains($path, 'debug-'))
            ->filter(fn (string $path) => Storage::disk('public')->size($path) >= 20000)
            ->values();

        $samples = [
            [
                'description' => '【调试】朝阳公园东门看到一只橘猫，戴红色项圈，往公园里走了',
                'found_at' => now()->subHours(5),
                'created_at' => now()->subDay(),
                'contact_public' => true,
            ],
            [
                'description' => '【调试】中关村大街看到黑白奶牛猫，没人牵，往北跑了',
                'found_at' => now()->subHours(2),
                'created_at' => now()->subHours(3),
                'contact_public' => false,
            ],
            [
                'description' => '【调试】望京小街看到金毛，没戴牵引绳，在便利店门口徘徊',
                'found_at' => now()->subDays(2),
                'created_at' => now()->subDays(2),
                'contact_public' => true,
            ],
            [
                'description' => '【调试】西单路口看到一只小型白狗，一直跟着行人',
                'found_at' => now()->subDays(4),
                'created_at' => now()->subDays(4),
                'contact_public' => false,
            ],
        ];

        foreach ($samples as $index => $sample) {
            $post = CluePost::query()
                ->where('user_id', $user->id)
                ->where('description', $sample['description'])
                ->first() ?? new CluePost;

            $post->user_id = $user->id;
            $post->description = $sample['description'];
            $post->province = '北京市';
            $post->city = '北京市';
            $post->found_at = $sample['found_at'];
            $post->contact_name = '调试线索人';
            $post->contact_phone = '13800000002';
            $post->contact_public = $sample['contact_public'];
            $post->status = CluePostStatus::Showing;
            $post->lit_until = now()->addDays(Setting::getInt('light_days'));
            $post->delisted_at = null;
            $post->reject_reason = null;
            $post->created_at = $sample['created_at'];
            $post->save();

            if ($post->images()->exists() || $sources->isEmpty()) {
                continue;
            }

            $source = $sources[$index % $sources->count()];
            $extension = pathinfo($source, PATHINFO_EXTENSION) ?: 'png';
            $path = 'uploads/'.$user->id.'/debug-clue-'.($index + 1).'.'.$extension;
            if (! Storage::disk('public')->exists($path)) {
                Storage::disk('public')->copy($source, $path);
            }
            $post->images()->create([
                'path' => $path,
                'sort' => 0,
            ]);
        }
    }
}
