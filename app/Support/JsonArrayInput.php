<?php

namespace App\Support;

/**
 * 微信小程序会把带数组的 POST 改成 GET。
 * 前端把数组收成 JSON 字符串后，这里再解回数组。
 */
class JsonArrayInput
{
    public static function decode(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || ($trimmed[0] !== '[' && $trimmed[0] !== '{')) {
            return $value;
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $value;
        }

        return $decoded;
    }
}
