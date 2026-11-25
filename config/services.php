<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'tencent_map' => [
        'key' => env('TENCENT_MAP_KEY'),
    ],

    'wechat' => [
        'mini_app_id' => env('WECHAT_APP_ID'),
        'mini_secret' => env('WECHAT_APP_SECRET'),
        'mch_id' => env('WECHAT_MCH_ID'),
        'api_v3_key' => env('WECHAT_API_V3_KEY'),
        'public_key_id' => env('WECHAT_PUBLIC_KEY_ID'),
        'private_key' => env('WECHAT_PRIVATE_KEY_PATH'),
        'cert' => env('WECHAT_CERT_PATH'),
        'public_key' => env('WECHAT_PUBLIC_KEY_PATH'),
        'notify_url' => rtrim((string) env('APP_URL', ''), '/').'/api/pay/wechat/notify',
        'msg_token' => env('WECHAT_MSG_TOKEN'),
        'msg_aes_key' => env('WECHAT_MSG_AES_KEY'),
    ],

];
