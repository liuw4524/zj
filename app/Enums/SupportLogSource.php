<?php

namespace App\Enums;

enum SupportLogSource: string
{
    case Payment = 'payment';
    case Correction = 'correction';

    public function label(): string
    {
        return match ($this) {
            self::Payment => '赠送',
            self::Correction => '纠错',
        };
    }
}
