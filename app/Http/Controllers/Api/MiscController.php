<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\RuleSection;
use App\Models\Setting;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;

class MiscController extends Controller
{
    private const CONTACT_KINDS = [
        'clue_contact_request',
        'clue_contact_approved',
        'clue_contact_rejected',
    ];

    public function notifications(Request $request)
    {
        $rows = Notification::query()
            ->with(['post:id,pet_name', 'cluePost:id,description'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get();

        $unreadGroups = [];
        foreach ($rows as $item) {
            if ($item->read_at === null) {
                $unreadGroups[$item->groupKey()] = true;
            }
        }

        $seen = [];
        $items = [];
        foreach ($rows as $item) {
            $key = $item->groupKey();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $items[] = $item;
        }

        usort($items, function (Notification $left, Notification $right) use ($unreadGroups) {
            $leftUnread = isset($unreadGroups[$left->groupKey()]);
            $rightUnread = isset($unreadGroups[$right->groupKey()]);
            if ($leftUnread !== $rightUnread) {
                return $leftUnread ? -1 : 1;
            }

            return $right->id <=> $left->id;
        });

        $perPage = 20;
        $page = max(1, $request->integer('page', 1));
        $slice = array_slice($items, ($page - 1) * $perPage, $perPage);
        $mapped = array_map(fn (Notification $item) => [
            'id' => $item->id,
            'kind' => $item->kind,
            'title' => $item->title,
            'body' => $item->body,
            'subject' => $this->subject($item),
            'post_id' => $item->post_id,
            'clue_post_id' => $item->clue_post_id,
            'unread' => isset($unreadGroups[$item->groupKey()]),
        ], $slice);

        return ApiResponse::paginated(new LengthAwarePaginator(
            $mapped,
            count($items),
            $perPage,
            $page,
        ));
    }

    public function unreadNotificationCount(Request $request)
    {
        $rows = Notification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->get(['id', 'kind', 'post_id', 'clue_post_id']);

        $groups = [];
        $prompt = [];
        foreach ($rows as $item) {
            $key = $item->groupKey();
            $groups[$key] = true;
            if (! in_array($item->kind, self::CONTACT_KINDS, true)) {
                $prompt[$key] = true;
            }
        }

        return ApiResponse::ok([
            'count' => count($groups),
            'prompt' => count($prompt),
        ]);
    }

    public function readNotification(Request $request, Notification $notification)
    {
        if ($notification->user_id !== $request->user()->id) {
            throw new BusinessException('无权操作', 403);
        }

        Notification::query()
            ->sameGroup($notification)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return ApiResponse::ok();
    }

    private function subject(Notification $item): ?string
    {
        $name = trim((string) ($item->post?->pet_name ?? ''));
        if ($name !== '') {
            return $name;
        }

        $text = trim(preg_replace('/\s+/u', ' ', (string) ($item->cluePost?->description ?? '')) ?? '');
        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, 20);
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
