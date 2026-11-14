<?php

namespace App\Enums;

enum MedalLevel: int
{
    case Bronze = 1;
    case Silver = 2;
    case Gold = 3;
    case Diamond = 4;
    case King = 5;

    public function label(): string
    {
        return match ($this) {
            self::Bronze => '铜',
            self::Silver => '银',
            self::Gold => '金',
            self::Diamond => '钻石',
            self::King => '王者',
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::Bronze => '公益新爪',
            self::Silver => '热心探爪',
            self::Gold => '公益达人',
            self::Diamond => '寻踪先锋',
            self::King => '爪迹王者',
        };
    }

    public function threshold(): int
    {
        return match ($this) {
            self::Bronze => 0,
            self::Silver => 7,
            self::Gold => 25,
            self::Diamond => 60,
            self::King => 120,
        };
    }

    public static function fromPoints(int $points): self
    {
        $matched = self::Bronze;
        foreach (self::cases() as $case) {
            if ($points >= $case->threshold()) {
                $matched = $case;
            }
        }

        return $matched;
    }

    public function raisedTo(self $other): self
    {
        return $other->value > $this->value ? $other : $this;
    }
}
