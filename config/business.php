<?php

declare(strict_types=1);

return [
    // DBの日時保存方式は変えず、営業日・日計・月計の境界だけをこのtimezoneで判定する。
    'timezone' => 'Asia/Tokyo',
    'sales_target_default_key' => 'sales.target.default_amount',
];
