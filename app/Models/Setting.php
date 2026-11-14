<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    public static function defaults(): array
    {
        return [
            'commission_rate' => '15',
            'reward_cap_fen' => '600000',
            'light_days' => '10',
            'remind_days_before' => '1',
            'contact_visible_hours' => '48',
            'cooling_days' => '3',
            'max_active_posts' => '1',
            'max_active_clue_posts' => '3',
            'max_daily_clue_posts' => '3',
            'home_today_new' => '0',
            'home_in_progress' => '0',
            'home_found' => '0',
            'support_wall_enabled' => '1',
            'post_review_enabled' => '1',
        ];
    }

    public static function reviewRequired(): bool
    {
        return static::getInt('post_review_enabled') === 1;
    }

    public static function imageKeys(): array
    {
        return [
            'rescue_team_image' => 'rescue-team',
            'contact_platform_image' => 'contact-platform',
        ];
    }

    public static function imagePath(string $key): string
    {
        if (! array_key_exists($key, static::imageKeys())) {
            return '';
        }

        $value = static::query()->where('key', $key)->value('value');

        return is_string($value) ? trim($value) : '';
    }

    protected static function booted(): void
    {
        static::saving(function (Setting $setting) {
            if (! array_key_exists($setting->key, static::imageKeys())) {
                return;
            }
            $value = $setting->value;
            if (is_array($value)) {
                $value = $value[0] ?? '';
            }
            $setting->value = is_string($value) ? $value : '';
        });
    }

    public static function labels(): array
    {
        return [
            'commission_rate' => '抽佣比例（%）',
            'reward_cap_fen' => '酬谢上限（元）',
            'light_days' => '点亮天数',
            'remind_days_before' => '到期提前提醒天数',
            'contact_visible_hours' => '联系方式可见小时',
            'cooling_days' => '冷静期天数',
            'max_active_posts' => '每用户同时有效帖上限',
            'max_active_clue_posts' => '每用户同时有效线索帖上限',
            'max_daily_clue_posts' => '每人每天线索帖上限',
            'home_today_new' => '首页今日新增',
            'home_in_progress' => '首页进行中',
            'home_found' => '首页已找回',
            'support_wall_enabled' => '支持墙开关（1 打开，其他关闭）',
            'post_review_enabled' => '发帖审核（1 需审核，0 发帖即展示）',
            'rescue_team_image' => '加入寻宠队',
            'contact_platform_image' => '联系平台',
        ];
    }

    public static function getInt(string $key): int
    {
        $value = static::query()->where('key', $key)->value('value');

        if ($value === null) {
            return (int) (static::defaults()[$key] ?? 0);
        }

        return (int) $value;
    }

    public static function publicPayload(): array
    {
        $payload = [];
        foreach (array_keys(static::defaults()) as $key) {
            $payload[$key] = static::getInt($key);
        }

        return $payload;
    }
}
