<?php

namespace App\Enums;

enum GrowthSource: string
{
    case DailyOpen = 'daily_open';
    case ClueApproved = 'clue_approved';
    case Share = 'share';
    case LeadSubmitted = 'lead_submitted';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
			self::DailyOpen => '点亮爪印',
            self::ClueApproved => '线索过审',
            self::Share => '转发分享',
            self::LeadSubmitted => '提交线索',
            self::Admin => '客服调整',
        };
    }

    public function points(): int
    {
        return match ($this) {
            self::DailyOpen => 1,
            self::ClueApproved => 5,
            self::Share => 2,
            self::LeadSubmitted => 5,
            self::Admin => 0,
        };
    }
}
