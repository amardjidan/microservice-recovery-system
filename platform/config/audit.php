<?php

return [
    // Token untuk header "Authorization: Bearer <token>". Isi AUDIT_API_TOKEN di .env.
    // Kalau kosong: API hanya terbuka saat APP_ENV=local, selain itu ditolak (401).
    'token' => env('AUDIT_API_TOKEN'),

    // Batas selisih debit-credit yang dianggap wajar (pembulatan).
    'balance_tolerance' => (float) env('AUDIT_BALANCE_TOLERANCE', 0.05),
];
