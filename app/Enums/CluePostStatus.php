<?php

namespace App\Enums;

enum CluePostStatus: int
{
    case PendingReview = 1;
    case Showing = 2;
    case Delisted = 3;
    case Withdrawn = 4;
    case Rejected = 9;

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => '待审核',
            self::Showing => '展示中',
            self::Delisted => '已下架',
            self::Withdrawn => '已撤回',
            self::Rejected => '已驳回',
        };
    }

    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
