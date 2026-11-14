<?php

namespace App\Services;

use App\Enums\EscrowStatus;
use App\Enums\PostStatus;
use App\Models\Lead;
use App\Models\Post;
use App\Models\User;
use App\Support\RefundWindow;
use App\Support\TraceCode;
use Illuminate\Support\Facades\Storage;

class PostPresenter
{
    public function __construct(private LeadService $leads) {}

    public function card(Post $post, User $viewer): array
    {
        $cover = $post->images->first();

        return [
            'id' => $post->id,
            'pet_name' => $post->pet_name,
            'breed' => $post->breed,
            'sex' => $post->sex,
            'province' => $post->province,
            'city' => $post->city,
            'district' => $post->district,
            'town' => $post->town,
            'lost_at' => $post->lost_at?->toIso8601String(),
            'reward_amount' => $post->reward_amount,
            'reward_topped_up' => (bool) $post->reward_topped_up,
            'escrow_to_platform' => (bool) $post->escrow_to_platform,
            'escrow_held' => $this->escrowHeld($post),
            'escrow_paid' => $this->escrowPaid($post),
            'status' => $post->status->value,
            'status_text' => $post->status->label(),
            'lit_until' => $post->lit_until?->toIso8601String(),
            'light_days_left' => $this->lightDaysLeft($post),
            'published_days' => $this->publishedDays($post),
            'view_count' => $post->view_count,
            'share_count' => $post->share_count,
            'forwarded_wechat' => (bool) $post->forwarded_wechat,
            'forwarded_douyin' => (bool) $post->forwarded_douyin,
            'forwarded_rescue_team' => (bool) $post->forwarded_rescue_team,
            'cover' => $cover ? Storage::disk('public')->url($cover->path) : null,
            'is_owner' => $viewer->id === $post->user_id,
            'trace_code' => $post->trace_code,
            'trace_share_text' => TraceCode::shareText($post->trace_code),
        ];
    }

    public function detail(Post $post, User $viewer): array
    {
        $reason = app(PostService::class)->withdrawBlockReason($post);
        $data = $this->card($post, $viewer);
        $data['description'] = $post->description;
        $data['pet_traits'] = $post->pet_traits;
        $data['lost_place'] = $post->lost_place;
        $data['lost_story'] = $post->lost_story;
        $data['images'] = $post->images->map(fn ($image) => [
            'url' => Storage::disk('public')->url($image->path),
        ])->values()->all();
        $data['reject_reason'] = $viewer->id === $post->user_id ? $post->reject_reason : null;
        $data['has_escrow'] = $post->escrow()->exists();
        $data['can_pay'] = $viewer->id === $post->user_id && $post->status === PostStatus::PendingPayment;
        $data['can_light'] = $viewer->id === $post->user_id && in_array($post->status, [PostStatus::Showing, PostStatus::Frozen], true);
        $data['can_withdraw'] = $viewer->id === $post->user_id && $reason === null;
        $data['withdraw_block_reason'] = $viewer->id === $post->user_id ? $reason : null;
        $data['refund_route'] = $viewer->id === $post->user_id ? $this->refundRoute($post) : 'none';

        return $data;
    }

    public function lead(Lead $lead, User $viewer): array
    {
        $visible = $this->leads->contactVisible($viewer, $lead);
        $post = $lead->post;
        $contact = null;

        if ($visible && $post && $viewer->id === $post->user_id) {
            $contact = ['name' => $lead->contact_name, 'phone' => $lead->contact_phone];
        }

        return [
            'id' => $lead->id,
            'post_id' => $lead->post_id,
            'is_post_owner' => $post && $viewer->id === $post->user_id,
            'content' => $lead->content,
            'kind' => $lead->kind->apiValue(),
            'kind_text' => $lead->kind->label(),
            'status' => $lead->status->value,
            'status_text' => $lead->statusLabel(),
            'contact' => $contact,
            'contact_visible' => $visible,
            'contact_visible_until' => $lead->contact_visible_until?->toIso8601String(),
            'settlement_status' => $this->leads->settlementStatus($viewer, $lead),
            'images' => $lead->images->map(fn ($image) => [
                'url' => Storage::disk('public')->url($image->path),
            ])->values()->all(),
            'post' => $post ? [
                'id' => $post->id,
                'pet_name' => $post->pet_name,
                'city' => $post->city,
                'status' => $post->status->value,
                'status_text' => $post->status->label(),
                'reward_amount' => $post->reward_amount,
                'escrow_to_platform' => (bool) $post->escrow_to_platform,
            ] : null,
        ];
    }

    private function publishedDays(Post $post): int
    {
        if ($post->created_at === null) {
            return 0;
        }

        return (int) $post->created_at->copy()->startOfDay()->diffInDays(now()->startOfDay(), true);
    }

    private function lightDaysLeft(Post $post): int
    {
        if ($post->lit_until === null || $post->lit_until->lessThanOrEqualTo(now())) {
            return 0;
        }

        return (int) ceil(now()->diffInSeconds($post->lit_until) / 86400);
    }

    private function refundRoute(Post $post): string
    {
        $order = $post->escrow;
        if ($order === null || $order->status !== EscrowStatus::Held || $order->paid_at === null) {
            return 'none';
        }

        if ($post->leads()->whereNotNull('matched_at')->exists()) {
            return 'review';
        }

        return RefundWindow::expired($order) ? 'offline' : 'original';
    }

    private function escrowHeld(Post $post): bool
    {
        $order = $post->escrow;

        return $order !== null && $order->status === EscrowStatus::Held;
    }

    private function escrowPaid(Post $post): bool
    {
        $order = $post->escrow;

        return $order !== null
            && $order->paid_at !== null
            && ! in_array($order->status, [EscrowStatus::Refunding, EscrowStatus::Refunded], true);
    }
}
