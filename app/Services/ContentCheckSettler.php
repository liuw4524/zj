<?php

namespace App\Services;

use App\Enums\CluePostStatus;
use App\Enums\PostStatus;
use App\Exceptions\BusinessException;
use App\Models\CluePost;
use App\Models\ContentCheck;
use App\Models\Post;
use App\Models\Setting;

class ContentCheckSettler
{
    public function __construct(
        private PostService $posts,
        private CluePostService $clues,
        private LeadService $leads,
    ) {}

    public function apply(string $traceId, string $suggest, ?int $label): void
    {
        $check = ContentCheck::query()->where('trace_id', $traceId)->first();
        if (! $check) {
            throw new BusinessException('检测记录不存在', 404);
        }
        if (! in_array($suggest, [ContentCheck::PASS, ContentCheck::REVIEW, ContentCheck::RISKY], true)) {
            $suggest = ContentCheck::REVIEW;
        }
        if ($check->status === ContentCheck::PENDING || ($suggest === ContentCheck::RISKY && $check->status !== ContentCheck::RISKY)) {
            $check->status = $suggest;
            $check->label = $label;
            $check->save();
        }

        $this->settle($check->subject_type, (int) $check->subject_id);
    }

    private function settle(string $type, int $id): void
    {
        if ($type === ContentCheck::POST) {
            $this->settlePost($id);

            return;
        }
        if ($type === ContentCheck::CLUE) {
            $this->settleClue($id);

            return;
        }
        if ($type === ContentCheck::LEAD) {
            $this->leads->releaseAfterSecurity($id);
        }
    }

    private function settlePost(int $id): void
    {
        $post = Post::query()->find($id);
        if (! $post) {
            return;
        }
        if (ContentCheck::hasRisky(ContentCheck::POST, $id)) {
            $this->posts->rejectForSecurity($post);

            return;
        }
        if (ContentCheck::holds(ContentCheck::POST, $id) || Setting::reviewRequired()) {
            return;
        }
        if ($post->status === PostStatus::PendingReview) {
            $this->posts->approve($post);
        }
    }

    private function settleClue(int $id): void
    {
        $post = CluePost::query()->find($id);
        if (! $post) {
            return;
        }
        if (ContentCheck::hasRisky(ContentCheck::CLUE, $id)) {
            $this->clues->rejectForSecurity($post);

            return;
        }
        if (ContentCheck::holds(ContentCheck::CLUE, $id) || Setting::reviewRequired()) {
            return;
        }
        if ($post->status === CluePostStatus::PendingReview) {
            $this->clues->approve($post);
        }
    }
}
