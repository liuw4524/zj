<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\RuleSection;
use App\Models\Setting;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MiscController extends Controller
{
    public function notifications(Request $request)
    {
        $paginator = Notification::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->paginate(20);

        $paginator->through(fn (Notification $item) => [
            'id' => $item->id,
            'kind' => $item->kind,
            'title' => $item->title,
            'body' => $item->body,
            'post_id' => $item->post_id,
        ]);

        return ApiResponse::paginated($paginator);
    }

    public function rules()
    {
        $sections = RuleSection::query()
            ->orderBy('sort')
            ->orderBy('id')
            ->get(['title', 'body']);

        return ApiResponse::ok([
            'title' => '自愿酬谢说明',
            'sections' => $sections,
        ]);
    }

    public function rescueTeam()
    {
        return $this->poster('rescue_team_image');
    }

    public function contactPlatform()
    {
        return $this->poster('contact_platform_image');
    }

    private function poster(string $key)
    {
        $path = Setting::imagePath($key);

        return ApiResponse::ok([
            'url' => $path === '' ? null : Storage::disk('public')->url($path),
        ]);
    }
}
