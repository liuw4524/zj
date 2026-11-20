<?php

namespace App\Services;

use App\Enums\AppealStatus;
use App\Enums\EscrowStatus;
use App\Enums\LeadKind;
use App\Enums\LeadStatus;
use App\Enums\PostStatus;
use App\Exceptions\BusinessException;
use App\Models\Appeal;
use App\Models\EscrowOrder;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Post;
use App\Models\ReceiptImage;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LeadService
{
    public int $growthAwarded = 0;

    public function __construct(
        private UploadService $uploads,
        private PostService $posts,
        private GrowthService $growth,
    ) {}

    public function create(User $user, Post $post, array $data): Lead
    {
        $user->assertNotBlocked();
        if ($user->id === $post->user_id) {
            throw new BusinessException('不能给自己的帖子提交线索');
        }

        if (! $post->isPubliclyVisible()) {
            throw new BusinessException('帖子未在展示');
        }

        foreach ($data['images'] ?? [] as $path) {
            $this->uploads->assertOwned($user, $path);
        }

        $this->growthAwarded = 0;

        return DB::transaction(function () use ($user, $post, $data) {
            $user->phone = $data['phone'];
            $user->save();

            $lead = new Lead();
            $lead->post_id = $post->id;
            $lead->user_id = $user->id;
            $lead->content = $data['content'];
            $lead->kind = LeadKind::fromApi($data['kind']);
            $lead->contact_name = $data['contact_name'];
            $lead->contact_phone = $data['contact_phone'];
            $lead->status = LeadStatus::Submitted;
            $lead->save();

            foreach (array_values($data['images'] ?? []) as $index => $path) {
                $lead->images()->create([
                    'path' => $path,
                    'sort' => $index,
                ]);
            }

            $this->growthAwarded = $this->growth->awardLead($user, $lead->id);

            return $lead->load('images');
        });
    }

    public function unreadCount(User $user): int
    {
        return Lead::query()
            ->whereNull('owner_seen_at')
            ->whereHas('post', fn ($query) => $query->where('user_id', $user->id))
            ->count();
    }

    public function incoming(User $user): LengthAwarePaginator
    {
        $unseenIds = Lead::query()
            ->whereNull('owner_seen_at')
            ->whereIn('post_id', Post::query()->where('user_id', $user->id)->select('id'))
            ->pluck('id');
        if ($unseenIds->isNotEmpty()) {
            Lead::query()->whereIn('id', $unseenIds)->update(['owner_seen_at' => now()]);
        }

        return Lead::query()
            ->with(['images', 'post.escrow', 'post.images'])
            ->whereIn('post_id', Post::query()->where('user_id', $user->id)->select('id'))
            ->orderByDesc('id')
            ->paginate(20);
    }

    public function forPost(User $user, Post $post): LengthAwarePaginator
    {
        if ($user->id !== $post->user_id) {
            throw new BusinessException('无权查看线索', 403);
        }

        return $post->leads()->with(['images', 'post.escrow'])->orderByDesc('id')->paginate(20);
    }

    public function show(User $user, Lead $lead): Lead
    {
        $lead->load(['images', 'post.escrow']);
        $post = $lead->post;
        $allowed = $user->id === $lead->user_id || ($post && $user->id === $post->user_id);
        if (! $allowed) {
            throw new BusinessException('无权查看线索', 403);
        }

        return $lead;
    }

    public function mine(User $user): LengthAwarePaginator
    {
        return Lead::query()
            ->with(['images', 'post.escrow', 'post.images'])
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate(20);
    }

    public function match(User $user, Lead $lead): Lead
    {
        $user->assertNotBlocked();
        $post = $lead->post;
        $this->assertOwner($user, $post);

        return DB::transaction(function () use ($user, $lead) {
            $lockedLead = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $post = Post::query()->whereKey($lockedLead->post_id)->lockForUpdate()->firstOrFail();
            $this->assertOwner($user, $post);
            $this->assertLeadMutable($post);

            if ($lockedLead->kind === LeadKind::Seen) {
                throw new BusinessException('目击线索不能找对');
            }

            if ($lockedLead->status !== LeadStatus::Submitted) {
                throw new BusinessException('该线索不能找对');
            }

            if (! in_array($post->status, [PostStatus::Showing, PostStatus::Handover], true)) {
                throw new BusinessException('当前帖子不能找对');
            }

            $taken = Lead::query()
                ->where('post_id', $post->id)
                ->where('id', '!=', $lockedLead->id)
                ->where('status', LeadStatus::Matched)
                ->exists();
            if ($taken) {
                throw new BusinessException('已有找对的线索，请先处理完再找对下一条');
            }

            $lockedLead->status = LeadStatus::Matched;
            $lockedLead->matched_at = now();
            $lockedLead->contact_visible_until = now()->addHours(Setting::getInt('contact_visible_hours'));
            $lockedLead->save();

            $post->status = PostStatus::Handover;
            $post->save();

            Notification::query()->firstOrCreate(
                ['dedupe_key' => 'handover:'.$lockedLead->id],
                [
                    'user_id' => $lockedLead->user_id,
                    'post_id' => $post->id,
                    'kind' => 'handover_confirm',
                    'title' => '请让宠主确认收到',
                    'body' => '宠主已找对。交出宠物前，请当面让宠主点「确认收到」。没点确认，平台不认定交接完成。',
                ]
            );

            return $lockedLead->refresh();
        });
    }

    public function reject(User $user, Lead $lead): Lead
    {
        $user->assertNotBlocked();
        $post = $lead->post;
        $this->assertOwner($user, $post);
        $this->assertLeadMutable($post);

        $allowed = $lead->kind === LeadKind::Seen
            ? [LeadStatus::Submitted, LeadStatus::Noted]
            : [LeadStatus::Submitted, LeadStatus::Matched];
        if (! in_array($lead->status, $allowed, true)) {
            throw new BusinessException($lead->kind === LeadKind::Seen ? '该线索不能标为与本案无关' : '该线索不能找错');
        }

        $lead->status = LeadStatus::Rejected;
        $lead->contact_visible_until = null;
        $lead->save();

        $this->posts->refreshAfterLead($post);

        return $lead->refresh();
    }

    public function acknowledge(User $user, Lead $lead): Lead
    {
        $user->assertNotBlocked();
        $post = $lead->post;
        $this->assertOwner($user, $post);
        $this->assertLeadMutable($post);

        if ($lead->kind !== LeadKind::Seen) {
            throw new BusinessException('只有目击线索可以标为已了解');
        }

        if ($lead->status !== LeadStatus::Submitted) {
            throw new BusinessException('该线索不能标为已了解');
        }

        $lead->status = LeadStatus::Noted;
        $lead->save();

        return $lead->refresh();
    }

    public function addReceipt(User $user, Lead $lead, UploadedFile $file): ReceiptImage
    {
        $this->assertCanConfirm($user, $lead);
        $count = $lead->receiptImages()->count();
        if ($count >= 4) {
            throw new BusinessException('最多上传 4 张接收照片');
        }

        $stored = $this->uploads->store($user, $file);

        return $lead->receiptImages()->create([
            'path' => $stored['path'],
            'sort' => $count,
        ]);
    }

    public function removeReceipt(User $user, Lead $lead, ReceiptImage $image): void
    {
        $this->assertCanConfirm($user, $lead);
        if ($image->lead_id !== $lead->id) {
            throw new BusinessException('图片无效');
        }

        $this->uploads->assertOwned($user, $image->path);
        Storage::disk('public')->delete($image->path);
        $image->delete();
    }

    public function clearReceipts(User $user, Lead $lead): void
    {
        $this->assertCanConfirm($user, $lead);
        $paths = $lead->receiptImages()->pluck('path')->all();
        foreach ($paths as $path) {
            Storage::disk('public')->delete($path);
        }
        $lead->receiptImages()->delete();
    }

    public function confirm(User $user, Lead $lead): Post
    {
        $this->assertCanConfirm($user, $lead);
        $post = $lead->post;
        $paths = $lead->receiptImages()->orderBy('sort')->pluck('path')->all();
        if (count($paths) < 1) {
            throw new BusinessException('请上传接收照片');
        }

        foreach ($paths as $path) {
            $this->uploads->assertOwned($user, $path);
        }

        return DB::transaction(function () use ($lead, $post) {
            if ($post->reward_amount === 0 || ! $post->escrow_to_platform) {
                return $this->posts->closeSettled($post->fresh());
            }

            $order = EscrowOrder::query()->where('post_id', $post->id)->lockForUpdate()->first();
            if (! $order || $order->status !== EscrowStatus::Held) {
                throw new BusinessException('托管单不存在');
            }

            $order->lead_id = $lead->id;
            $order->save();
            app(EscrowService::class)->moveToPendingOffline($order);

            return $this->posts->closeSettled($post->fresh());
        });
    }

    public function notMine(User $user, Lead $lead): Lead
    {
        $user->assertNotBlocked();
        $post = $lead->post;
        $this->assertOwner($user, $post);
        $this->assertLeadMutable($post);

        if ($lead->kind === LeadKind::Seen) {
            throw new BusinessException('目击线索不能标记不是我的宠物');
        }

        if ($lead->status !== LeadStatus::Matched) {
            throw new BusinessException('当前不能标记不是我的宠物');
        }

        $lead->status = LeadStatus::HandoverFailed;
        $lead->contact_visible_until = null;
        $lead->save();

        $this->posts->refreshAfterLead($post);

        return $lead->refresh()->load('post');
    }

    public function appeal(User $user, Post $post, string $content): Appeal
    {
        $user->assertNotBlocked();
        $this->assertOwner($user, $post);

        return DB::transaction(function () use ($user, $post, $content) {
            $locked = Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();
            $order = EscrowOrder::query()->where('post_id', $locked->id)->lockForUpdate()->first();

            if ($locked->status !== PostStatus::Cooling || ! $order || $order->status !== EscrowStatus::Cooling) {
                throw new BusinessException('当前不能申诉');
            }

            $appeal = new Appeal();
            $appeal->post_id = $locked->id;
            $appeal->lead_id = $order->lead_id;
            $appeal->user_id = $user->id;
            $appeal->content = $content;
            $appeal->status = AppealStatus::Pending;
            $appeal->save();

            $order->status = EscrowStatus::AppealFrozen;
            $order->save();

            return $appeal;
        });
    }

    public function releaseAppeal(Appeal $appeal): Appeal
    {
        return DB::transaction(function () use ($appeal) {
            $locked = Appeal::query()->whereKey($appeal->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== AppealStatus::Pending) {
                throw new BusinessException('申诉已处理');
            }

            $order = EscrowOrder::query()->where('post_id', $locked->post_id)->lockForUpdate()->firstOrFail();
            if ($order->status !== EscrowStatus::AppealFrozen) {
                throw new BusinessException('资金单未冻结');
            }

            app(EscrowService::class)->moveToPendingOffline($order);
            $locked->status = AppealStatus::Released;
            $locked->save();

            return $locked;
        });
    }

    public function upholdAppeal(Appeal $appeal): Appeal
    {
        return DB::transaction(function () use ($appeal) {
            $locked = Appeal::query()->whereKey($appeal->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== AppealStatus::Pending) {
                throw new BusinessException('申诉已处理');
            }

            $order = EscrowOrder::query()->where('post_id', $locked->post_id)->lockForUpdate()->firstOrFail();
            $order->status = EscrowStatus::Held;
            $order->cooling_started_at = null;
            $order->settle_due_at = null;
            $order->commission_rate = null;
            $order->commission_amount = null;
            $order->payee_amount = null;
            $order->save();

            if ($order->lead_id) {
                Lead::query()->whereKey($order->lead_id)->update([
                    'status' => LeadStatus::HandoverFailed->value,
                    'contact_visible_until' => null,
                ]);
            }

            $post = $locked->post;
            $post->status = PostStatus::Handover;
            $post->save();
            $this->posts->refreshAfterLead($post);

            $locked->status = AppealStatus::Upheld;
            $locked->save();

            return $locked;
        });
    }

    public function contactVisible(User $viewer, Lead $lead): bool
    {
        $post = $lead->post;
        $party = $viewer->id === $post->user_id || $viewer->id === $lead->user_id;
        if (! $party) {
            return false;
        }

        if ($lead->kind === LeadKind::Seen && $post->reward_amount > 0) {
            return $viewer->id === $post->user_id;
        }

        if ($post->reward_amount === 0) {
            return true;
        }

        return $lead->status === LeadStatus::Matched
            && $lead->contact_visible_until !== null
            && $lead->contact_visible_until->isFuture();
    }

    public function settlementStatus(User $viewer, Lead $lead): ?string
    {
        if ($viewer->id !== $lead->user_id) {
            return null;
        }

        $order = $lead->post->escrow;
        if (! $order || $order->lead_id !== $lead->id) {
            return null;
        }

        return match ($order->status) {
            EscrowStatus::RefundReview => '宠主申请退款，待平台处理',
            EscrowStatus::PendingOffline => '待平台线下打款',
            EscrowStatus::Settled => '已结清',
            default => null,
        };
    }

    private function assertCanConfirm(User $user, Lead $lead): void
    {
        $user->assertNotBlocked();
        $post = $lead->post;
        $this->assertOwner($user, $post);

        if ($lead->kind === LeadKind::Seen) {
            throw new BusinessException('目击线索不能确认收到');
        }

        if ($lead->status !== LeadStatus::Matched || $post->status !== PostStatus::Handover) {
            throw new BusinessException('当前不能确认收到');
        }
    }

    private function assertOwner(User $user, Post $post): void
    {
        if ($user->id !== $post->user_id) {
            throw new BusinessException('无权操作该帖子', 403);
        }
    }

    private function assertLeadMutable(Post $post): void
    {
        if (in_array($post->status, [
            PostStatus::Cooling,
            PostStatus::PendingSettlement,
            PostStatus::Closed,
            PostStatus::Withdrawn,
        ], true)) {
            throw new BusinessException('当前帖子不能处理线索');
        }
    }
}
