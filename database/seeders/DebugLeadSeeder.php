<?php

namespace Database\Seeders;

use App\Enums\LeadKind;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\Post;
use App\Models\User;
use App\Support\DebugUsers;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class DebugLeadSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::query()->where('email', DebugUsers::OWNER)->first();
        if (! $owner) {
            return;
        }

        $sources = collect(Storage::disk('public')->files('uploads/'.$owner->id))
            ->reject(fn (string $path) => str_contains($path, 'debug-'))
            ->filter(fn (string $path) => Storage::disk('public')->size($path) >= 20000)
            ->values();

        $samples = [
            '假帖小白' => '【调试】朝阳公园北门捡到一只白色短腿柯基，公的，会回应小白，现在在我这里',
            '假帖橘子' => '【调试】国贸附近捡到一只母橘猫，脖子有铃铛，现在在我这里',
            '假帖煤球' => '【调试】三里屯捡到一只黑色卷毛田园犬，现在在我这里',
        ];

        $index = 0;
        foreach ($samples as $petName => $content) {
            $post = Post::query()->where('pet_name', $petName)->first();
            if (! $post || $post->user_id === $owner->id) {
                continue;
            }

            $lead = Lead::query()
                ->where('post_id', $post->id)
                ->where('content', $content)
                ->first();
            $fresh = $lead === null;
            if ($fresh) {
                $lead = new Lead;
                $lead->post_id = $post->id;
                $lead->user_id = $owner->id;
                $lead->content = $content;
                $lead->kind = LeadKind::Held;
                $lead->contact_name = $owner->name ?: '杰克';
                $lead->contact_phone = $owner->phone ?: '13800000001';
            }
            $lead->status = LeadStatus::Submitted;
            $lead->matched_at = null;
            $lead->contact_visible_until = null;
            $lead->owner_seen_at = null;
            $lead->save();

            if (! $fresh && $lead->images()->exists()) {
                continue;
            }

            if ($sources->isEmpty()) {
                continue;
            }

            $source = $sources[$index % $sources->count()];
            $extension = pathinfo($source, PATHINFO_EXTENSION) ?: 'png';
            $path = 'uploads/'.$owner->id.'/debug-lead-'.($index + 1).'.'.$extension;
            if (! Storage::disk('public')->exists($path)) {
                Storage::disk('public')->copy($source, $path);
            }
            $lead->images()->create([
                'path' => $path,
                'sort' => 0,
            ]);
            $index++;
        }
    }
}
