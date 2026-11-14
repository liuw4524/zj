<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Enums\EscrowStatus;
use App\Enums\LeadStatus;
use App\Enums\PostStatus;
use App\Exceptions\BusinessException;
use App\Models\EscrowOrder;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Support\Money;
use App\Support\RefundWindow;
use App\Support\TraceCode;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PostService
{
    public ?array $pendingPayment = null;

    public function __construct(
        private UploadService $uploads,
        private PaymentGateway $gateway,
    ) {}

    public function pullPayment(): ?array
    {
        $payment = $this->pendingPayment;
        $this->pendingPayment = null;

        return $payment;
    }

    public function create(User $user, array $data): Post
    {
        if ($user->is_blocked) {
            throw new BusinessException('账号已限制发布', 403);
        }

        $reward = (int) $data['reward_amount'];
        $cap = Setting::getInt('reward_cap_fen');
        if ($reward > $cap) {
            throw new BusinessException('酬谢超出上限'.$this->yuanLabel($cap).'元');
        }

        foreach ($data['images'] as $path) {
            $this->uploads->assertOwned($user, $path);
        }

        try {
            return DB::transaction(function () use ($user, $data, $reward) {
                $user->phone = $data['phone'];
                $user->save();

                $post = new Post();
                $post->user_id = $user->id;
                $post->pet_name = $data['pet_name'];
                $post->breed = $data['breed'] ?? null;
                $post->sex = $data['sex'];
                $post->description = $data['description'];
                $post->pet_traits = $data['pet_traits'] ?? null;
                $post->province = $data['province'];
                $post->city = $data['city'];
                $post->district = $data['district'] ?? null;
                $post->town = $data['town'] ?? null;
                $post->lost_place = $data['lost_place'];
                $post->lost_story = $data['lost_story'] ?? null;
                $post->lost_at = $data['lost_at'];
                $post->reward_amount = $reward;
                $post->escrow_to_platform = $reward > 0 && (bool) $data['escrow_to_platform'];
                $post->contact_name = $data['contact_name'];
                $post->contact_phone = $data['contact_phone'];
                if ($post->escrow_to_platform) {
                    $post->status = PostStatus::PendingPayment;
                } elseif (Setting::reviewRequired()) {
                    $post->status = PostStatus::PendingReview;
                } else {
                    $post->status = PostStatus::Showing;
                    $post->lit_until = now()->addDays(Setting::getInt('light_days'));
                }
                $post->active_key = $this->allocateActiveKey($user);
                $post->save();

                foreach (array_values($data['images']) as $index => $path) {
                    $post->images()->create([
                        'path' => $path,
                        'sort' => $index,
                    ]);
                }

                if (in_array($post->status, [PostStatus::PendingReview, PostStatus::Showing], true)) {
                    $this->assignTraceCode($post);
                }

                return $post->load('images');
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23000') {
                throw new BusinessException('已达到同时有效帖上限');
            }

            throw $e;
        }
    }

    public function publicList(array $filters): LengthAwarePaginator
    {
        $query = Post::query()
            ->with(['images', 'escrow'])
            ->where('status', PostStatus::Showing)
            ->where('lit_until', '>', now());

        foreach (['province', 'city', 'district', 'town'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (($filters['has_reward'] ?? null) === '1') {
            $query->where('reward_amount', '>', 0);
        }

        if (($filters['has_reward'] ?? null) === '0') {
            $query->where('reward_amount', 0);
        }

        $from = match ($filters['lost_within'] ?? null) {
            '3d' => now()->subDays(3),
            '1m' => now()->subMonth(),
            '6m' => now()->subMonths(6),
            default => null,
        };

        if ($from) {
            $query->where('lost_at', '>=', $from);
        }

        return $query->orderByDesc('id')->paginate(10);
    }

    public function myPosts(User $user): LengthAwarePaginator
    {
        return Post::query()
            ->with(['images', 'escrow'])
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate(10);
    }

    public function show(User $viewer, Post $post, bool $countView = true): Post
    {
        $post->load(['images', 'escrow']);

        if ($viewer->id !== $post->user_id && ! $post->isPubliclyVisible() && ! $this->viewerHasLead($viewer, $post)) {
            throw new BusinessException('帖子不存在', 404);
        }

        if ($countView && $viewer->id !== $post->user_id && $post->isPubliclyVisible()) {
            $post->increment('view_count');
            $post->refresh()->load(['images', 'escrow']);
        }

        return $post;
    }

    public function approve(Post $post): Post
    {
        if ($post->status !== PostStatus::PendingReview) {
            throw new BusinessException('当前状态不能通过审核');
        }

        $post->status = PostStatus::Showing;
        $post->lit_until = now()->addDays(Setting::getInt('light_days'));
        $post->reject_reason = null;
        $post->save();

        return $post;
    }

    public function reject(Post $post, string $reason): Post
    {
        return DB::transaction(function () use ($post, $reason) {
            $locked = Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PostStatus::PendingReview) {
                throw new BusinessException('当前状态不能驳回');
            }

            $order = EscrowOrder::query()->where('post_id', $locked->id)->lockForUpdate()->first();
            if ($order && in_array($order->status, [EscrowStatus::Held, EscrowStatus::AppealFrozen], true)) {
                $this->refundHeld($locked, $order, false);
            }

            $locked->reject_reason = $reason;
            $locked->status = PostStatus::Rejected;
            $locked->active_key = null;
            $locked->save();

            return $locked;
        });
    }

    public function pay(User $user, Post $post): EscrowOrder
    {
        $this->assertOwner($user, $post);

        if ($post->reward_amount === 0 || ! $post->escrow_to_platform) {
            throw new BusinessException('该帖无需支付');
        }

        $order = DB::transaction(function () use ($post) {
            $locked = Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();
            $existing = EscrowOrder::query()->where('post_id', $locked->id)->lockForUpdate()->first();

            if ($existing && $existing->paid_at) {
                $this->assignTraceCode($locked);

                return $existing;
            }

            if ($locked->status !== PostStatus::PendingPayment) {
                throw new BusinessException('当前状态不能支付');
            }

            $order = $existing ?: new EscrowOrder();
            $order->post_id = $locked->id;
            $order->user_id = $locked->user_id;
            $order->amount = $locked->reward_amount;
            $order->status = $this->gateway->usesWechat() ? EscrowStatus::Unpaid : EscrowStatus::Held;
            $order->out_trade_no = $order->out_trade_no ?: ('E'.Str::ulid());
            $order->save();

            return $order;
        });

        if ($order->paid_at) {
            return $order;
        }

        $this->pendingPayment = $this->gateway->charge($order);
        $order->refresh();
        if ($order->paid_at) {
            $this->finishEscrowPayment($order);
        }

        return $order->refresh();
    }

    public function finishEscrowPayment(EscrowOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $lockedOrder = EscrowOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $locked = Post::query()->whereKey($lockedOrder->post_id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PostStatus::PendingPayment) {
                return;
            }

            $lockedOrder->status = EscrowStatus::Held;
            $lockedOrder->paid_at = $lockedOrder->paid_at ?: now();
            $lockedOrder->save();

            if (Setting::reviewRequired()) {
                $locked->status = PostStatus::PendingReview;
            } else {
                $locked->status = PostStatus::Showing;
                $locked->lit_until = now()->addDays(Setting::getInt('light_days'));
            }
            $locked->save();
            $this->assignTraceCode($locked);

            if ($locked->status === PostStatus::PendingReview) {
                app(OpsAlert::class)->once(
                    'escrow-review:'.$lockedOrder->id,
                    '新托管待审核',
                    '「'.$locked->pet_name.'」托管 '.Money::yuan($lockedOrder->amount).'，通过后才会展示。',
                );
            }
        });
    }

    public function addReward(User $user, Post $post, int $addAmount): Post
    {
        $user->assertNotBlocked();
        $this->assertOwner($user, $post);

        if ($addAmount < 1) {
            throw new BusinessException('追加金额必须大于 0');
        }

        $topup = null;
        $result = DB::transaction(function () use ($post, $addAmount, &$topup) {
            $locked = Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [
                PostStatus::PendingPayment,
                PostStatus::PendingReview,
                PostStatus::Showing,
                PostStatus::Frozen,
                PostStatus::Handover,
            ], true)) {
                throw new BusinessException('当前状态不能追加酬谢');
            }

            $next = $locked->reward_amount + $addAmount;
            if ($next <= $locked->reward_amount) {
                throw new BusinessException('酬谢不能降低');
            }

            $cap = Setting::getInt('reward_cap_fen');
            if ($next > $cap) {
                throw new BusinessException('酬谢超出上限'.$this->yuanLabel($cap).'元');
            }

            $order = EscrowOrder::query()->where('post_id', $locked->id)->lockForUpdate()->first();
            if ($order && $order->paid_at && $this->gateway->usesWechat()) {
                $topup = $order;

                return $locked;
            }

            $locked->reward_amount = $next;
            $locked->reward_topped_up = true;
            $locked->save();

            if ($order && $order->status === EscrowStatus::Held) {
                $order->amount = $next;
                $order->save();
                $this->gateway->charge($order);
            }

            return $locked->refresh();
        });

        if ($topup) {
            $this->pendingPayment = $this->gateway->chargeTopup($topup, $addAmount);
        }

        return $result;
    }

    public function withdraw(User $user, Post $post): Post
    {
        $user->assertNotBlocked();
        $this->assertOwner($user, $post);

        return DB::transaction(function () use ($post) {
            $locked = Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();

            if ($locked->leads()->where('status', LeadStatus::Matched)->exists()) {
                throw new BusinessException('有进行中的找对，请先把该线索标为找错');
            }

            if (! in_array($locked->status, [
                PostStatus::PendingPayment,
                PostStatus::PendingReview,
                PostStatus::Showing,
                PostStatus::Frozen,
            ], true)) {
                throw new BusinessException('当前状态不能撤回');
            }

            $order = EscrowOrder::query()->where('post_id', $locked->id)->lockForUpdate()->first();
            if ($order && in_array($order->status, [EscrowStatus::Held, EscrowStatus::AppealFrozen], true)) {
                if ($locked->leads()->whereNotNull('matched_at')->exists()) {
                    $this->holdRefundForReview($locked, $order);
                } else {
                    $this->refundHeld($locked, $order, true);
                }
            }

            $locked->status = PostStatus::Withdrawn;
            $locked->active_key = null;
            $locked->save();
            $this->closeOpenLeads($locked);

            return $locked;
        });
    }

    public function light(User $user, Post $post): Post
    {
        $user->assertNotBlocked();
        $this->assertOwner($user, $post);

        if (! in_array($post->status, [PostStatus::Showing, PostStatus::Frozen], true)) {
            throw new BusinessException('当前状态不能点亮');
        }

        $post->lit_until = now()->addDays(Setting::getInt('light_days'));
        $post->status = PostStatus::Showing;
        $post->save();

        return $post;
    }

    public function findByTrace(User $viewer, string $code): Post
    {
        $normalized = TraceCode::normalize($code);
        if ($normalized === null) {
            throw new BusinessException('未找到该爪迹编号', 404);
        }

        $post = Post::query()->where('trace_code', $normalized)->first();
        if ($post === null) {
            throw new BusinessException('未找到该爪迹编号', 404);
        }

        if (in_array($post->status, [PostStatus::Frozen, PostStatus::Closed, PostStatus::Withdrawn], true)) {
            throw new BusinessException('帖子已下架或不存在', 404);
        }

        if (in_array($post->status, [PostStatus::PendingPayment, PostStatus::PendingReview, PostStatus::Rejected], true)
            && $viewer->id !== $post->user_id) {
            throw new BusinessException('帖子不存在', 404);
        }

        return $this->show($viewer, $post, false);
    }

    public function share(Post $post): Post
    {
        if (! $post->isPubliclyVisible() && $post->status !== PostStatus::Frozen) {
            throw new BusinessException('帖子不存在', 404);
        }

        $post->increment('share_count');

        return $post->refresh();
    }

    public function updateCounts(Post $post, int $viewCount, int $shareCount): Post
    {
        $post->view_count = $viewCount;
        $post->share_count = $shareCount;
        $post->save();

        return $post;
    }

    public function closeSettled(Post $post): Post
    {
        $post->status = PostStatus::Closed;
        $post->active_key = null;
        $post->save();

        $post->leads()
            ->whereIn('status', [LeadStatus::Submitted->value, LeadStatus::Matched->value])
            ->update(['status' => LeadStatus::ClosedWithCase->value]);

        return $post;
    }

    public function forceClose(Post $post): Post
    {
        $post->load('escrow');

        if ($post->reward_amount === 0 || ! $post->escrow_to_platform) {
            if (! in_array($post->status, [PostStatus::Showing, PostStatus::Frozen, PostStatus::Handover, PostStatus::PendingReview], true)) {
                throw new BusinessException('当前状态不能结案');
            }

            return $this->closeSettled($post);
        }

        $order = $post->escrow;
        if (! $order) {
            throw new BusinessException('没有可结清的资金单');
        }

        if ($order->status === EscrowStatus::Held) {
            throw new BusinessException('托管中的单据请使用协助退款');
        }

        app(EscrowService::class)->markSettled($order);

        return $post->refresh();
    }

    public function assistRefund(Post $post): Post
    {
        return DB::transaction(function () use ($post) {
            $locked = Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();
            $order = EscrowOrder::query()->where('post_id', $locked->id)->lockForUpdate()->first();

            if (! $order || ! in_array($order->status, [EscrowStatus::Held, EscrowStatus::AppealFrozen], true)) {
                throw new BusinessException('当前资金状态不能协助退款');
            }

            if (RefundWindow::expired($order)) {
                throw new BusinessException('已超过微信退款期限，请到资金单标记已线下退回');
            }

            app(EscrowService::class)->refund($order);

            $locked->leads()
                ->where('status', LeadStatus::Matched->value)
                ->update(['status' => LeadStatus::Rejected->value]);
            $this->closeOpenLeads($locked);

            $locked->status = PostStatus::Withdrawn;
            $locked->active_key = null;
            $locked->save();

            return $locked;
        });
    }

    public function refreshAfterLead(Post $post): Post
    {
        $post->refresh();

        if (in_array($post->status, [
            PostStatus::Cooling,
            PostStatus::PendingSettlement,
            PostStatus::Closed,
            PostStatus::Withdrawn,
            PostStatus::PendingPayment,
            PostStatus::PendingReview,
            PostStatus::Rejected,
        ], true)) {
            return $post;
        }

        $hasMatched = $post->leads()->where('status', LeadStatus::Matched)->exists();

        if ($hasMatched) {
            $post->status = PostStatus::Handover;
        } elseif ($post->status === PostStatus::Handover) {
            $post->status = ($post->lit_until && $post->lit_until->isFuture())
                ? PostStatus::Showing
                : PostStatus::Frozen;
        }

        $post->save();

        return $post;
    }

    public function withdrawBlockReason(Post $post): ?string
    {
        if ($post->leads()->where('status', LeadStatus::Matched)->exists()) {
            return '有进行中的找对，请先把该线索标为找错';
        }

        if (! in_array($post->status, [
            PostStatus::PendingPayment,
            PostStatus::PendingReview,
            PostStatus::Showing,
            PostStatus::Frozen,
        ], true)) {
            return '当前状态不能撤回';
        }

        return null;
    }

    private function holdRefundForReview(Post $post, EscrowOrder $order): void
    {
        $lead = $post->leads()->whereNotNull('matched_at')->latest('matched_at')->first();
        $order->status = EscrowStatus::RefundReview;
        $order->lead_id = $lead?->id;
        $order->save();

        if ($lead) {
            Notification::query()->firstOrCreate(
                ['dedupe_key' => 'refund-review:'.$order->id],
                [
                    'user_id' => $lead->user_id,
                    'post_id' => $post->id,
                    'kind' => 'refund_review',
                    'title' => '宠主申请退回托管金',
                    'body' => '如果宠物已经交出、对方没点确认收到，请联系平台。',
                ],
            );
        }

        app(OpsAlert::class)->once(
            'escrow-refund-review:'.$order->id,
            '待审批退款',
            '「'.$post->pet_name.'」撤回前有过找对，托管 '.Money::yuan($order->amount).' 等你确认退不退。',
        );
    }

    private function refundHeld(Post $post, EscrowOrder $order, bool $alertOriginal): void
    {
        if (RefundWindow::expired($order)) {
            app(OpsAlert::class)->once(
                'escrow-refund-offline:'.$order->id,
                '超期退款',
                '「'.$post->pet_name.'」已超过微信退款期限，托管 '.Money::yuan($order->amount).' 请线下退回。',
            );

            return;
        }

        app(EscrowService::class)->refund($order);

        if ($alertOriginal) {
            app(OpsAlert::class)->once(
                'escrow-refund:'.$order->id,
                '撤单退款',
                '「'.$post->pet_name.'」已撤回，托管 '.Money::yuan($order->amount).' 已原路退回。',
            );
        }
    }

    private function closeOpenLeads(Post $post): void
    {
        $post->leads()
            ->whereIn('status', [LeadStatus::Submitted->value, LeadStatus::Noted->value])
            ->update(['status' => LeadStatus::ClosedWithCase->value]);
    }

    private function assignTraceCode(Post $post): void
    {
        if ($post->trace_code) {
            return;
        }

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = TraceCode::make();
            if (Post::query()->where('trace_code', $code)->exists()) {
                continue;
            }

            $post->trace_code = $code;
            $post->save();

            return;
        }

        throw new BusinessException('编号生成失败，请重试');
    }

    private function yuanLabel(int $fen): string
    {
        if ($fen % 100 === 0) {
            return (string) intdiv($fen, 100);
        }

        return rtrim(rtrim(number_format($fen / 100, 2, '.', ''), '0'), '.');
    }

    private function allocateActiveKey(User $user): string
    {
        $limit = max(1, Setting::getInt('max_active_posts'));
        $used = Post::query()
            ->where('user_id', $user->id)
            ->whereNotNull('active_key')
            ->lockForUpdate()
            ->pluck('active_key');

        if ($used->count() >= $limit) {
            throw new BusinessException('已达到同时有效帖上限');
        }

        if ($limit === 1) {
            return (string) $user->id;
        }

        for ($i = 1; $i <= $limit; $i++) {
            $key = $user->id.'-'.$i;
            if (! $used->contains($key)) {
                return $key;
            }
        }

        throw new BusinessException('已达到同时有效帖上限');
    }

    private function viewerHasLead(User $viewer, Post $post): bool
    {
        return $post->leads()->where('user_id', $viewer->id)->exists();
    }

    private function assertOwner(User $user, Post $post): void
    {
        if ($user->id !== $post->user_id) {
            throw new BusinessException('无权操作该帖子', 403);
        }
    }
}
