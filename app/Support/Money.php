<?php

namespace App\Support;

class Money
{
    public static function yuan(int|string|null $fen): ?string
    {
        if ($fen === null || $fen === '') {
            return null;
        }

        return number_format(((int) $fen) / 100, 2).' 元';
    }

    public static function toFen(int|float|string $yuan): string
    {
        $normalized = number_format((float) $yuan, 2, '.', '');
        [$whole, $cents] = explode('.', $normalized);

        return (string) (((int) $whole * 100) + (int) $cents);
    }
}
