<?php

namespace App\Services;

use App\Enums\GrowthSource;
use App\Enums\MedalLevel;
use App\Models\GrowthLog;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GrowthService
{
    public const SHARE_DAILY_CAP = 3;

    public function visit(User $user): int
    {
        return $this->grant(
            $user,
            GrowthSource::DailyOpen,
            GrowthSource::DailyOpen->points(),
            'daily:'.$user->id.':'.$this->today(),
        );
    }

    public function awardClueApproved(User $user, int $cluePostId): int
    {
        return $this->grant(
            $user,
            GrowthSource::ClueApproved,
            GrowthSource::ClueApproved->points(),
            'clue_approved:'.$cluePostId,
            'clue_post',
            $cluePostId,
        );
    }

    public function awardShare(User $user, string $subjectType, int $subjectId): int
    {
        return $this->grant(
            $user,
            GrowthSource::Share,
            GrowthSource::Share->points(),
            'share:'.$user->id.':'.$subjectType.':'.$subjectId.':'.$this->today(),
            $subjectType,
            $subjectId,
        );
    }

    public function awardLead(User $user, int $leadId): int
    {
        return $this->grant(
            $user,
            GrowthSource::LeadSubmitted,
            GrowthSource::LeadSubmitted->points(),
            'lead:'.$leadId,
            'lead',
            $leadId,
        );
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

        $this->grant(
            $user,
            GrowthSource::Admin,
            $delta,
            null,
            null,
            null,
            $note,
            $operator->id,
        );

        return $user->refresh();
    }

    public function summary(User $user): array
    {
        $level = MedalLevel::from((int) $user->medal_level);
        $next = MedalLevel::tryFrom($level->value + 1);
        $points = (int) $user->growth_points;

        return [
            'level' => $level->value,
            'name' => $level->label(),
            'title' => $level->title(),
            'points' => $points,
            'points_to_next' => $next === null ? null : max(0, $next->threshold() - $points),
            'next_name' => $next?->label(),
            'next_title' => $next?->title(),
            'daily_claimed' => GrowthLog::query()
                ->where('user_id', $user->id)
                ->where('source', GrowthSource::DailyOpen)
                ->whereDate('earned_on', $this->today())
                ->exists(),
        ];
    }

    public function detail(User $user): array
    {
        $level = MedalLevel::from((int) $user->medal_level);
        $summary = $this->summary($user);
        $summary['levels'] = array_map(fn (MedalLevel $case) => [
            'level' => $case->value,
            'name' => $case->label(),
            'title' => $case->title(),
            'threshold' => $case->threshold(),
            'current' => $case === $level,
        ], MedalLevel::cases());
        $summary['logs'] = GrowthLog::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (GrowthLog $log) => [
                'id' => $log->id,
                'points' => $log->points,
                'source' => $log->source->value,
                'source_text' => $log->source->label(),
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return $summary;
    }

    private function grant(
        User $user,
        GrowthSource $source,
        int $points,
        ?string $dedupe,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?string $note = null,
        ?int $operatorId = null,
    ): int {
        try {
            $applied = DB::transaction(function () use ($user, $source, $points, $dedupe, $subjectType, $subjectId, $note, $operatorId) {
                $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                if ($locked->is_blocked && $source !== GrowthSource::Admin) {
                    return 0;
                }
                if ($dedupe !== null && GrowthLog::query()->where('dedupe_key', $dedupe)->exists()) {
                    return 0;
                }
                if ($source === GrowthSource::Share && $this->shareCountToday($locked) >= self::SHARE_DAILY_CAP) {
                    return 0;
                }

                $balance = (int) $locked->growth_points;
                $next = $balance + $points;
                if ($source === GrowthSource::Admin) {
                    $next = max(0, $next);
                }
                $applied = $next - $balance;
                if ($applied === 0) {
                    throw ValidationException::withMessages(['delta' => '成长值没有变化']);
                }

                $level = MedalLevel::from((int) $locked->medal_level)->raisedTo(MedalLevel::fromPoints($next));
                $locked->growth_points = $next;
                $locked->medal_level = $level->value;
                $locked->save();

                GrowthLog::query()->create([
                    'user_id' => $locked->id,
                    'points' => $applied,
                    'balance_after' => $next,
                    'medal_level_after' => $level->value,
                    'source' => $source,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'earned_on' => $this->today(),
                    'dedupe_key' => $dedupe,
                    'note' => $note,
                    'operator_id' => $operatorId,
                ]);

                return $applied;
            });
        } catch (UniqueConstraintViolationException) {
            return 0;
        }

        $user->refresh();

        return $applied;
    }

    private function shareCountToday(User $user): int
    {
        return GrowthLog::query()
            ->where('user_id', $user->id)
            ->where('source', GrowthSource::Share)
            ->whereDate('earned_on', $this->today())
            ->count();
    }

    private function today(): string
    {
        return now()->toDateString();
    }
}
