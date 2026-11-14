<?php

namespace App\Services;

use App\Enums\SupporterTitle;
use App\Enums\SupportLogSource;
use App\Enums\SupportOrderStatus;
use App\Exceptions\BusinessException;
use App\Contracts\PaymentGateway;
use App\Models\Setting;
use App\Models\SupportLog;
use App\Models\SupportOrder;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupportService
{
    public const TIERS = [1, 2, 5, 10, 20, 50, 100];

    public ?array $pendingPayment = null;

    public function __construct(private PaymentGateway $gateway) {}

    public function pullPayment(): ?array
    {
        $payment = $this->pendingPayment;
        $this->pendingPayment = null;

        return $payment;
    }

    public const MAX_PAW_COUNT = 42949672;

    public const MAX_BALANCE = 4294967295;

    public function overview(User $user): array
    {
        return [
            'tiers' => self::TIERS,
            'custom_min' => 1,
            'yuan_per_paw' => 1,
            'wall_enabled' => $this->wallEnabled(),
            'wall' => $this->wall(),
            'supporter' => SupporterTitle::payload($user),
            'copy' => '赠送小爪印，支持爪迹做得更好。1 爪印 = 1 元，款项归平台。',
            'limit_hint' => '大额以支付渠道限额为准。超限时请换成小额分笔，或联系客服。',
        ];
    }

    public function give(User $user, int $pawCount, bool $anonymous, string $clientToken): SupportOrder
    {
        try {
            $order = DB::transaction(function () use ($user, $pawCount, $anonymous, $clientToken) {
                $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $existing = $this->findToken($locked->id, $clientToken);
                if ($existing) {
                    return $existing;
                }

                $this->assertCanGive($locked);
                $next = (int) $locked->support_paws + $pawCount;
                if ($next > self::MAX_BALANCE) {
                    throw new BusinessException('累计过大，请联系客服');
                }

                $order = new SupportOrder;
                $order->user_id = $locked->id;
                $order->paw_count = $pawCount;
                $order->amount = $pawCount * 100;
                $order->anonymous = $anonymous;
                $order->out_trade_no = 'S'.Str::ulid();
                $order->client_token = $clientToken;
                if ($this->gateway->usesWechat()) {
                    $order->status = SupportOrderStatus::Pending;
                    $order->paid_at = null;
                    $order->save();

                    return $order;
                }

                $order->status = SupportOrderStatus::Paid;
                $order->paid_at = now();
                $order->save();

                $locked->support_paws = $next;
                $locked->save();

                SupportLog::query()->create([
                    'user_id' => $locked->id,
                    'paws' => $pawCount,
                    'balance_after' => $next,
                    'source' => SupportLogSource::Payment,
                    'support_order_id' => $order->id,
                    'note' => null,
                    'operator_id' => null,
                ]);

                return $order;
            });
        } catch (UniqueConstraintViolationException) {
            $order = $this->findToken($user->id, $clientToken);
            if (! $order) {
                throw new BusinessException('请重试');
            }
        }

        if ($this->gateway->usesWechat() && $order->paid_at === null) {
            $this->pendingPayment = $this->gateway->chargeSupport($order);
        }

        $user->refresh();

        return $order;
    }

    public function findOwn(User $user, int $orderId): SupportOrder
    {
        $order = SupportOrder::query()
            ->where('user_id', $user->id)
            ->whereKey($orderId)
            ->first();
        if (! $order) {
            throw new BusinessException('订单不存在', 404);
        }

        return $order;
    }

    public function orderPayload(SupportOrder $order, User $user): array
    {
        return [
            'id' => $order->id,
            'paw_count' => (int) $order->paw_count,
            'amount' => (int) $order->amount,
            'anonymous' => (bool) $order->anonymous,
            'out_trade_no' => $order->out_trade_no,
            'paid_at' => $order->paid_at?->toIso8601String(),
            'supporter' => SupporterTitle::payload($user),
        ];
    }

    public function adjust(User $user, int $delta, string $note, User $operator): User
    {
        $note = trim($note);
        if ($note === '') {
            throw ValidationException::withMessages(['note' => '请填写原因']);
        }
        if ($delta === 0) {
            throw ValidationException::withMessages(['delta' => '请填写增减数值']);
        }

        DB::transaction(function () use ($user, $delta, $note, $operator) {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $next = (int) $locked->support_paws + $delta;
            if ($next > self::MAX_BALANCE) {
                throw ValidationException::withMessages(['delta' => '累计过大']);
            }
            $next = max(0, $next);
            $applied = $next - (int) $locked->support_paws;
            if ($applied === 0) {
                throw ValidationException::withMessages(['delta' => '累计没有变化']);
            }

            $locked->support_paws = $next;
            $locked->save();

            SupportLog::query()->create([
                'user_id' => $locked->id,
                'paws' => $applied,
                'balance_after' => $next,
                'source' => SupportLogSource::Correction,
                'support_order_id' => null,
                'note' => $note,
                'operator_id' => $operator->id,
            ]);
        });

        return $user->refresh();
    }

    private function assertCanGive(User $user): void
    {
        if ($user->is_blocked) {
            throw new BusinessException('账号已限制', 403);
        }
    }

    private function findToken(int $userId, string $clientToken): ?SupportOrder
    {
        return SupportOrder::query()
            ->where('user_id', $userId)
            ->where('client_token', $clientToken)
            ->first();
    }

    private function wallEnabled(): bool
    {
        return Setting::getInt('support_wall_enabled') === 1;
    }

    private function wall(): array
    {
        if (! $this->wallEnabled()) {
            return [];
        }

        return SupportOrder::query()
            ->with('user:id,name')
            ->where('status', SupportOrderStatus::Paid)
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(function (SupportOrder $order) {
                $name = $order->anonymous ? '匿名' : ($order->user->name ?: '支持者');

                return [
                    'name' => $name,
                    'paws' => (int) $order->paw_count,
                    'text' => $name.'赠送了'.$order->paw_count.'枚小爪印',
                ];
            })
            ->all();
    }
}
