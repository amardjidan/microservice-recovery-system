<?php

return [
    // Token untuk header "Authorization: Bearer <token>". Isi AUDIT_API_TOKEN di .env.
    // Kalau kosong: API hanya terbuka saat APP_ENV=local, selain itu ditolak (401).
    'token' => env('AUDIT_API_TOKEN'),

    // Alamat API yang dipanggil tampilan web (harus server lain, bukan server web itu sendiri saat dev).
    'api_url' => env('AUDIT_API_URL', 'http://127.0.0.1:8001/api/audit'),

    // Batas selisih debit-credit yang dianggap wajar (pembulatan).
    'balance_tolerance' => (float) env('AUDIT_BALANCE_TOLERANCE', 0.05),
];
