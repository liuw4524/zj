<?php

namespace App\Support;

class TraceCode
{
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function make(): string
    {
        $code = 'ZJ';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < 4; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    public static function normalize(string $raw): ?string
    {
        $code = strtoupper(preg_replace('/\s+/u', '', trim($raw)) ?? '');
        if (! preg_match('/^ZJ['.self::ALPHABET.']{4,8}$/', $code)) {
            return null;
        }

        return $code;
    }

    public static function shareText(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return '抖音搜/小程序搜爪迹编号 '.$code;
    }
}
