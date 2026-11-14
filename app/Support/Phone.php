<?php

namespace App\Support;

class Phone
{
    public static function normalize(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (is_float($value)) {
            $value = sprintf('%.0f', $value);
        }
        if (! is_string($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (str_starts_with($digits, '86') && strlen($digits) === 13) {
            $digits = substr($digits, 2);
        }

        return $digits === '' ? null : $digits;
    }
}
