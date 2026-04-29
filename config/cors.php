<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    | ใช้เปิด CORS สำหรับ frontend (Next.js) ที่อยู่คนละโดเมนกับ backend (Laravel)
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter([
        env('FRONTEND_URL'),
        env('FRONTEND_URL_2'),
        // เพิ่มโดเมน dev สำหรับเรียกจาก localhost
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ]),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // true = ส่ง cookie ข้ามโดเมนได้ (จำเป็นถ้าใช้ Sanctum SPA mode)
    // ถ้าใช้ Bearer token อย่างเดียวจะตั้ง false ก็ได้ แต่เปิดไว้ปลอดภัยกว่า
    'supports_credentials' => true,
];
