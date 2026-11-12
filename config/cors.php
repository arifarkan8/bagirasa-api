<?php

/*
|--------------------------------------------------------------------------
| Pengaturan CORS BagiRasa
|--------------------------------------------------------------------------
| Frontend belum diputuskan, jadi daftar alamat yang diizinkan dibaca dari
| .env (CORS_ALLOWED_ORIGINS, dipisah koma). Kalau kosong, dipakai alamat
| pengembangan yang umum (Next.js, Vite, dan sejenisnya).
*/

$bawaan = [
    'http://localhost:3000',
    'http://127.0.0.1:3000',
    'http://localhost:5173',
    'http://127.0.0.1:5173',
];

$dariEnv = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
)));

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $dariEnv ?: $bawaan,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With'],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 600,

    // Autentikasi memakai token bearer, bukan cookie, jadi tidak perlu credentials.
    'supports_credentials' => false,

];