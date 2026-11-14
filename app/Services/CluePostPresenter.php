<?php

namespace App\Services;

use App\Enums\ClueContactRequestStatus;
use App\Enums\CluePostStatus;
use App\Models\ClueContactRequest;
use App\Models\CluePost;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class CluePostPresenter
{
    public function card(CluePost $post, User $viewer): array
    {
        $cover = $post->images->first();

        return [
            'id' => $post->id,
            'description' => $post->description,
            'province' => $post->province,
            'city' => $post->city,
            'found_at' => $post->found_at?->toIso8601String(),
            'status' => $post->status->value,
            'status_text' => $post->status->label(),
            'contact_public' => (bool) $post->contact_public,
            'published_days' => $this->publishedDays($post),
            'lit_until' => $post->lit_until?->toIso8601String(),
            'light_days_left' => $this->lightDaysLeft($post),
            'cover' => $cover ? Storage::disk('public')->url($cover->path) : null,
            'is_owner' => $viewer->id === $post->user_id,
            'pending_request_count' => (int) ($post->pending_request_count ?? 0),
        ];
    }

    public function detail(CluePost $post, User $viewer): array
    {
        $post->loadMissing(['images', 'contactRequests.user']);
        $data = $this->card($post, $viewer);
        if ($viewer->id === $post->user_id) {
            $data['pending_request_count'] = $post->contactRequests
                ->where('status', ClueContactRequestStatus::Pending)
                ->count();
        }
        $data['images'] = $post->images->map(fn ($image) => [
            'url' => Storage::disk('public')->url($image->path),
        ])->values()->all();
        $data['contact'] = $this->visibleContact($post, $viewer);
        $data['can_request_contact'] = $this->canRequest($post, $viewer);
        $data['my_request_status'] = $this->myRequestStatus($post, $viewer);
        $data['can_light'] = $viewer->id === $post->user_id
            && in_array($post->status, [CluePostStatus::Showing, CluePostStatus::Delisted], true);
        $data['can_withdraw'] = $viewer->id === $post->user_id
            && ! in_array($post->status, [CluePostStatus::Withdrawn], true);
        $data['reject_reason'] = $viewer->id === $post->user_id ? $post->reject_reason : null;
        $data['requests'] = $viewer->id === $post->user_id
            ? $post->contactRequests->sortByDesc('id')->map(fn (ClueContactRequest $request) => $this->requestItem($request))->values()->all()
            : [];

        return $data;
    }

    public function requestItem(ClueContactRequest $request): array
    {
        $request->loadMissing(['user', 'cluePost']);

        return [
            'id' => $request->id,
            'clue_post_id' => $request->clue_post_id,
            'status' => $request->status->value,
            'status_text' => $request->status->label(),
            'applicant_name' => $request->user?->name ?: '用户',
            'summary' => $request->cluePost?->description,
        ];
    }

    private function visibleContact(CluePost $post, User $viewer): ?array
    {
        if ($viewer->id === $post->user_id || $this->contactGranted($post, $viewer)) {
            return [
                'name' => $post->contact_name,
                'phone' => $post->contact_phone,
            ];
        }

        return null;
    }

    private function contactGranted(CluePost $post, User $viewer): bool
    {
        if ($post->contact_public && $post->isPubliclyVisible()) {
            return true;
        }

        return $post->contactRequests->contains(fn (ClueContactRequest $request) => $request->user_id === $viewer->id
            && $request->status === ClueContactRequestStatus::Approved);
    }

    private function canRequest(CluePost $post, User $viewer): bool
    {
        if ($viewer->id === $post->user_id || ! $post->isPubliclyVisible() || $post->contact_public) {
            return false;
        }

        $mine = $post->contactRequests->where('user_id', $viewer->id);
        if ($mine->contains(fn (ClueContactRequest $request) => $request->status === ClueContactRequestStatus::Approved)) {
            return false;
        }

        return ! $mine->contains(fn (ClueContactRequest $request) => $request->status === ClueContactRequestStatus::Pending);
    }

    private function myRequestStatus(CluePost $post, User $viewer): ?string
    {
        if ($viewer->id === $post->user_id) {
            return null;
        }

        $latest = $post->contactRequests
            ->where('user_id', $viewer->id)
            ->sortByDesc('id')
            ->first();

        return $latest?->status->label();
    }

    private function publishedDays(CluePost $post): int
    {
        if ($post->created_at === null) {
            return 0;
        }

        return (int) $post->created_at->copy()->startOfDay()->diffInDays(now()->startOfDay(), true);
    }

    private function lightDaysLeft(CluePost $post): int
    {
        if ($post->status !== CluePostStatus::Showing || $post->lit_until === null || $post->lit_until->lessThanOrEqualTo(now())) {
            return 0;
        }

        return (int) ceil(now()->diffInSeconds($post->lit_until, true) / 86400);
    }
}
