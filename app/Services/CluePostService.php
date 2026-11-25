<?php

namespace App\Services;

use App\Enums\ClueContactRequestStatus;
use App\Enums\CluePostStatus;
use App\Exceptions\BusinessException;
use App\Models\ClueContactRequest;
use App\Models\CluePost;
use App\Models\ContentCheck;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CluePostService
{
    public const PURGE_DAYS = 30;

    public function __construct(
        private UploadService $uploads,
        private GrowthService $growth,
        private WechatContentSecurity $security,
    ) {}

    public function create(User $user, array $data): CluePost
    {
        if ($user->is_blocked) {
            throw new BusinessException('账号已限制发布', 403);
        }

        foreach ($data['images'] as $path) {
            $this->uploads->assertOwned($user, $path);
        }

        $text = $this->security->inspectText($user, [
            $data['description'],
            $data['contact_name'],
        ]);
        $machineHold = $this->security->delaysPublish($text, $data['images']);

        $post = DB::transaction(function () use ($user, $data, $machineHold) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $active = CluePost::query()
                ->where('user_id', $user->id)
                ->whereIn('status', [
                    CluePostStatus::PendingReview->value,
                    CluePostStatus::Showing->value,
                    CluePostStatus::Delisted->value,
                ])
                ->count();
            if ($active >= Setting::getInt('max_active_clue_posts')) {
                throw new BusinessException('已达到同时有效线索帖上限');
            }
            $dailyCap = Setting::getInt('max_daily_clue_posts');
            $postedToday = CluePost::query()
                ->where('user_id', $user->id)
                ->whereDate('created_at', now()->toDateString())
                ->count();
            if ($postedToday >= $dailyCap) {
                throw new BusinessException('今日线索帖已达上限');
            }

            $user->phone = $data['phone'];
            $user->save();

            $post = new CluePost();
            $post->user_id = $user->id;
            $post->description = $data['description'];
            $post->province = $data['province'];
            $post->city = $data['city'];
            $post->found_at = $data['found_at'];
            $post->contact_name = $data['contact_name'];
            $post->contact_phone = $data['contact_phone'];
            $post->contact_public = (bool) $data['contact_public'];
            $post->status = CluePostStatus::PendingReview;
            $post->save();

            foreach (array_values($data['images']) as $index => $path) {
                $post->images()->create([
                    'path' => $path,
                    'sort' => $index,
                ]);
            }

            if (! Setting::reviewRequired() && ! $machineHold) {
                return $this->approve($post)->load('images');
            }

            return $post->load('images');
        });

        if ($text === ContentCheck::REVIEW) {
            $this->security->rememberTextReview($user, ContentCheck::CLUE, $post->id);
        }
        try {
            $this->security->submitImages($user, ContentCheck::CLUE, $post->id, $data['images']);
        } catch (BusinessException $e) {
            $this->rejectForSecurity($post);
            throw $e;
        }

        return $post->refresh()->load('images');
    }

    public function publicList(string $province, string $city): LengthAwarePaginator
    {
        return CluePost::query()
            ->with('images')
            ->where('status', CluePostStatus::Showing)
            ->where('lit_until', '>', now())
            ->where('province', $province)
            ->where('city', $city)
            ->orderByDesc('id')
            ->paginate(10);
    }

    public function myPosts(User $user): LengthAwarePaginator
    {
        return CluePost::query()
            ->with('images')
            ->withCount(['contactRequests as pending_request_count' => function ($query) {
                $query->where('status', ClueContactRequestStatus::Pending);
            }])
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate(10);
    }

    public function show(User $viewer, CluePost $post): CluePost
    {
        $post->load(['images', 'contactRequests.user']);

        if ($viewer->id !== $post->user_id && ! $post->isPubliclyVisible()) {
            throw new BusinessException('线索不存在', 404);
        }

        return $post;
    }

    public function approve(CluePost $post): CluePost
    {
        $this->security->assertReadyForAdmin(ContentCheck::CLUE, $post->id);
        if ($post->status !== CluePostStatus::PendingReview) {
            throw new BusinessException('当前状态不能通过审核');
        }

        $post->status = CluePostStatus::Showing;
        $post->lit_until = now()->addDays(Setting::getInt('light_days'));
        $post->delisted_at = null;
        $post->reject_reason = null;
        $post->save();

        $awarded = $this->growth->awardClueApproved($post->user, $post->id);
        if ($awarded > 0) {
            Notification::query()->create([
                'user_id' => $post->user_id,
                'post_id' => null,
                'kind' => 'growth',
                'title' => '公益成长值 +'.$awarded,
                'body' => Setting::reviewRequired() ? '你的公益线索已通过审核' : '你的公益线索已发布',
                'dedupe_key' => 'growth:clue:'.$post->id,
            ]);
        }

        return $post;
    }

    public function share(User $user, CluePost $post): int
    {
        $post = $this->show($user, $post);
        if (! in_array($post->status, [CluePostStatus::Showing, CluePostStatus::Delisted], true)) {
            return 0;
        }

        return $this->growth->awardShare($user, 'clue_post', $post->id);
    }

    public function rejectForSecurity(CluePost $post): void
    {
        $post = CluePost::query()->whereKey($post->id)->first();
        if (! $post || in_array($post->status, [CluePostStatus::Rejected, CluePostStatus::Withdrawn], true)) {
            return;
        }

        $post->status = CluePostStatus::Rejected;
        $post->reject_reason = '内容未通过安全检测';
        $post->lit_until = null;
        $post->save();

        Notification::query()->firstOrCreate(
            ['dedupe_key' => 'security:clue:'.$post->id],
            [
                'user_id' => $post->user_id,
                'clue_post_id' => $post->id,
                'kind' => 'security',
                'title' => '内容未通过安全检测',
                'body' => '请修改文字或图片后重新发布。',
            ],
        );
    }

    public function reject(CluePost $post, string $reason): CluePost
    {
        if ($post->status !== CluePostStatus::PendingReview) {
            throw new BusinessException('当前状态不能驳回');
        }

        $post->status = CluePostStatus::Rejected;
        $post->reject_reason = $reason;
        $post->save();

        return $post;
    }

    public function delist(CluePost $post): CluePost
    {
        if ($post->status !== CluePostStatus::Showing) {
            throw new BusinessException('当前状态不能下架');
        }

        $post->status = CluePostStatus::Delisted;
        $post->delisted_at = now();
        $post->save();

        return $post;
    }

    public function light(User $user, CluePost $post): CluePost
    {
        $user->assertNotBlocked();
        $this->assertOwner($user, $post);

        if (! in_array($post->status, [CluePostStatus::Showing, CluePostStatus::Delisted], true)) {
            throw new BusinessException('当前状态不能点亮');
        }

        $post->status = CluePostStatus::Showing;
        $post->lit_until = now()->addDays(Setting::getInt('light_days'));
        $post->delisted_at = null;
        $post->save();

        return $post;
    }

    public function updateContact(User $user, CluePost $post, bool $public): CluePost
    {
        $user->assertNotBlocked();
        $this->assertOwner($user, $post);

        if ($post->status === CluePostStatus::Withdrawn) {
            throw new BusinessException('当前状态不能修改');
        }

        $post->contact_public = $public;
        $post->save();

        return $post->load(['images', 'contactRequests.user']);
    }

    public function withdraw(User $user, CluePost $post): CluePost
    {
        $user->assertNotBlocked();
        $this->assertOwner($user, $post);

        if ($post->status === CluePostStatus::Withdrawn) {
            throw new BusinessException('当前状态不能撤回');
        }

        return DB::transaction(function () use ($post) {
            $locked = CluePost::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();
            $locked->load('images');
            $this->deleteImages($locked);
            $locked->contactRequests()
                ->where('status', ClueContactRequestStatus::Pending)
                ->update(['status' => ClueContactRequestStatus::Cancelled->value]);
            $locked->status = CluePostStatus::Withdrawn;
            $locked->lit_until = null;
            $locked->save();

            return $locked->load(['images', 'contactRequests.user']);
        });
    }

    public function requestContact(User $viewer, CluePost $post): ClueContactRequest
    {
        $viewer->assertNotBlocked();
        if ($viewer->id === $post->user_id) {
            throw new BusinessException('不能申请自己的联系方式');
        }
        if (! $post->isPubliclyVisible()) {
            throw new BusinessException('线索不存在', 404);
        }
        if ($post->contact_public) {
            throw new BusinessException('联系方式已经公开');
        }

        $exists = ClueContactRequest::query()
            ->where('clue_post_id', $post->id)
            ->where('user_id', $viewer->id)
            ->where('status', ClueContactRequestStatus::Pending)
            ->exists();
        if ($exists) {
            throw new BusinessException('已有进行中的申请');
        }

        $approved = ClueContactRequest::query()
            ->where('clue_post_id', $post->id)
            ->where('user_id', $viewer->id)
            ->where('status', ClueContactRequestStatus::Approved)
            ->exists();
        if ($approved) {
            throw new BusinessException('已经可以查看联系方式');
        }

        $request = new ClueContactRequest();
        $request->clue_post_id = $post->id;
        $request->user_id = $viewer->id;
        $request->status = ClueContactRequestStatus::Pending;
        $request->save();

        $summary = trim(preg_replace('/\s+/u', ' ', (string) $post->description) ?? '');
        $summary = $summary === '' ? '你的公益线索' : '「'.mb_substr($summary, 0, 20).'」';
        Notification::query()->firstOrCreate(
            ['dedupe_key' => 'clue-contact:'.$request->id],
            [
                'user_id' => $post->user_id,
                'clue_post_id' => $post->id,
                'kind' => 'clue_contact_request',
                'title' => '有人申请查看联系方式',
                'body' => '有人申请查看'.$summary.'的联系方式',
            ]
        );

        return $request->load(['user', 'cluePost']);
    }

    public function approveRequest(User $owner, ClueContactRequest $request): ClueContactRequest
    {
        $owner->assertNotBlocked();
        $request->load('cluePost');
        $this->assertOwner($owner, $request->cluePost);
        if ($request->status !== ClueContactRequestStatus::Pending) {
            throw new BusinessException('当前申请不能同意');
        }

        $request->status = ClueContactRequestStatus::Approved;
        $request->save();
        $this->notifyApplicant($request, true);

        return $request->load(['user', 'cluePost']);
    }

    public function rejectRequest(User $owner, ClueContactRequest $request): ClueContactRequest
    {
        $owner->assertNotBlocked();
        $request->load('cluePost');
        $this->assertOwner($owner, $request->cluePost);
        if ($request->status !== ClueContactRequestStatus::Pending) {
            throw new BusinessException('当前申请不能拒绝');
        }

        $request->status = ClueContactRequestStatus::Rejected;
        $request->save();
        $this->notifyApplicant($request, false);

        return $request->load(['user', 'cluePost']);
    }

    public function cancelRequest(User $viewer, ClueContactRequest $request): ClueContactRequest
    {
        $viewer->assertNotBlocked();
        if ($request->user_id !== $viewer->id) {
            throw new BusinessException('不能取消别人的申请', 403);
        }
        if ($request->status !== ClueContactRequestStatus::Pending) {
            throw new BusinessException('当前申请不能取消');
        }

        $request->status = ClueContactRequestStatus::Cancelled;
        $request->save();

        return $request->load(['user', 'cluePost']);
    }

    private function notifyApplicant(ClueContactRequest $request, bool $approved): void
    {
        $summary = trim(preg_replace('/\s+/u', ' ', (string) ($request->cluePost?->description ?? '')) ?? '');
        $summary = $summary === '' ? '这条公益线索' : '「'.mb_substr($summary, 0, 20).'」';
        $kind = $approved ? 'clue_contact_approved' : 'clue_contact_rejected';
        Notification::query()->firstOrCreate(
            ['dedupe_key' => $kind.':'.$request->id],
            [
                'user_id' => $request->user_id,
                'clue_post_id' => $request->clue_post_id,
                'kind' => $kind,
                'title' => $approved ? '联系方式申请已通过' : '联系方式申请未通过',
                'body' => $approved
                    ? '可以查看'.$summary.'的联系方式'
                    : '暂不能查看'.$summary.'的联系方式',
            ]
        );
    }

    public function contactBadge(User $user): array
    {
        $incoming = ClueContactRequest::query()
            ->where('status', ClueContactRequestStatus::Pending)
            ->whereHas('cluePost', fn ($query) => $query->where('user_id', $user->id))
            ->count();
        $results = Notification::query()
            ->where('user_id', $user->id)
            ->whereIn('kind', ['clue_contact_approved', 'clue_contact_rejected'])
            ->whereNull('read_at')
            ->count();

        return [
            'incoming' => $incoming,
            'results' => $results,
            'count' => $incoming + $results,
        ];
    }

    public function myRequests(User $viewer): LengthAwarePaginator
    {
        Notification::query()
            ->where('user_id', $viewer->id)
            ->whereIn('kind', ['clue_contact_approved', 'clue_contact_rejected'])
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return ClueContactRequest::query()
            ->with(['cluePost', 'user'])
            ->where('user_id', $viewer->id)
            ->orderByDesc('id')
            ->paginate(10);
    }

    public function delistExpired(): int
    {
        $count = 0;
        CluePost::query()
            ->where('status', CluePostStatus::Showing)
            ->whereNotNull('lit_until')
            ->where('lit_until', '<=', now())
            ->orderBy('id')
            ->each(function (CluePost $post) use (&$count) {
                $post->status = CluePostStatus::Delisted;
                $post->delisted_at = $post->lit_until;
                $post->save();
                $count++;
            });

        return $count;
    }

    public function purgeStale(): int
    {
        $count = 0;
        CluePost::query()
            ->with('images')
            ->where('status', CluePostStatus::Delisted)
            ->whereNotNull('delisted_at')
            ->where('delisted_at', '<=', now()->subDays(self::PURGE_DAYS))
            ->orderBy('id')
            ->each(function (CluePost $post) use (&$count) {
                DB::transaction(function () use ($post) {
                    $locked = CluePost::query()->whereKey($post->id)->lockForUpdate()->first();
                    if (! $locked || $locked->status !== CluePostStatus::Delisted || $locked->delisted_at === null) {
                        return;
                    }
                    if ($locked->delisted_at->greaterThan(now()->subDays(self::PURGE_DAYS))) {
                        return;
                    }
                    $locked->load('images');
                    $this->deleteImages($locked);
                    $locked->contactRequests()->delete();
                    $locked->delete();
                });
                $count++;
            });

        return $count;
    }

    private function deleteImages(CluePost $post): void
    {
        foreach ($post->images as $image) {
            Storage::disk('public')->delete($image->path);
        }
        $post->images()->delete();
    }

    private function assertOwner(User $user, CluePost $post): void
    {
        if ($user->id !== $post->user_id) {
            throw new BusinessException('只能操作自己的线索帖', 403);
        }
    }
}
