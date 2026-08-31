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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'hiptime' => [
        // token ที่ agent (สคริปต์ฝั่งเครื่องลูก) ส่งมาใน header X-HipTime-Token เพื่อยืนยันตัวตนแบบ machine-to-machine
        'token' => env('HIPTIME_INGEST_TOKEN'),
        // ค่า timetype จาก Transcantime ที่ถือว่าเป็นเข้า/ออก (ค่ามาตรฐาน ZKTeco-compatible: 0=เข้า,4=OT เข้า / 1=ออก,5=OT ออก) — ปรับผ่าน .env ได้โดยไม่ต้องแก้โค้ด ถ้าเครื่องจริงใช้ค่าอื่น
        // หมายเหตุ: ใช้ trim ไม่ใช่ array_filter เฉยๆ เพราะ array_filter ทิ้งสตริง "0" ทิ้งด้วย (falsy ใน PHP)
        'checkin_types'  => array_values(array_filter(array_map('trim', explode(',', (string) env('HIPTIME_CHECKIN_TYPES', 'IN'))), fn ($v) => $v !== '')),
        'checkout_types' => array_values(array_filter(array_map('trim', explode(',', (string) env('HIPTIME_CHECKOUT_TYPES', 'OUT'))), fn ($v) => $v !== '')),
    ],

    'labour_importer' => [
        // token ที่ labour-app-importer ส่งมาใน header X-Labour-Importer-Token เพื่อสร้างใบมัดจำอัตโนมัติเมื่อชำระเงินครบ (machine-to-machine)
        'token' => env('LABOUR_IMPORTER_TOKEN'),
    ],

    'labour' => [
        'base_url' => env('LABOUR_API_BASE_URL', 'https://charoenmunconcrete.net'),
        'key'      => env('LABOUR_API_KEY'),
        'timeout'  => (int) env('LABOUR_API_TIMEOUT', 15),
    ],

];
