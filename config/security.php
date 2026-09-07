<?php

declare(strict_types=1);

return [
    // 顧客 PII の等価検索用 HMAC キー（APP_KEY とは独立。ローテーションは全行再計算が必要）
    'pii_lookup_key' => env('PII_LOOKUP_KEY'),
];
