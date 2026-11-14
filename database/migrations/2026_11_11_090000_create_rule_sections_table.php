<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rule_sections', function (Blueprint $table) {
            $table->id();
            $table->string('title', 40);
            $table->text('body');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        $setting = function (string $key, string $default): string {
            if (! Schema::hasTable('settings')) {
                return $default;
            }

            $value = DB::table('settings')->where('key', $key)->value('value');

            return is_string($value) && $value !== '' ? $value : $default;
        };

        $rate = $setting('commission_rate', '15');
        $capYuan = number_format(((int) $setting('reward_cap_fen', '600000')) / 100, 0, '.', '');
        $light = $setting('light_days', '10');
        $hours = $setting('contact_visible_hours', '48');
        $now = now();

        DB::table('rule_sections')->insert([
            [
                'title' => '寻宠感谢金',
                'body' => '酬谢是宠主自愿给出的寻宠感谢金，单帖上限 '.$capYuan.' 元。填 0 为无偿。大于 0 时可以交给平台托管，也可以不交、由双方自己结清。',
                'sort' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => '抽佣',
                'body' => '交给平台且成功结案时，平台按 '.$rate.'% 计佣。余款由平台线下付给线索人，不退回宠主。不交给平台的不抽佣。',
                'sort' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => '退款',
                'body' => '支付成功后 365 天内撤回，已托管金额原路退回。超过 365 天不能原路退回，请联系平台。没交给平台或尚未支付的不退款。存在进行中的找对时，需先把该线索标为找错才能撤回。已结案不能退款。',
                'sort' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => '点亮与联系',
                'body' => '帖子点亮 '.$light.' 天后需再次点亮，否则不再公开展示。找对后双方联系方式可见 '.$hours.' 小时。',
                'sort' => 4,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => '线下结清',
                'body' => '宠主确认收到并上传凭证后，丢失帖结案。交给平台的酬谢由平台线下付给线索人。不交给平台的由双方自己结清。不再设置冷静期，也不能申诉。',
                'sort' => 5,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_sections');
    }
};
