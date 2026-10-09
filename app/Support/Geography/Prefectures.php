<?php

declare(strict_types=1);

namespace App\Support\Geography;

/** 顧客カルテの都道府県の選択肢（JIS順）。分析は都道府県・市区町村までで、番地は持たない。 */
final class Prefectures
{
    /** @return list<string> */
    public static function all(): array
    {
        return __('messages.geography.prefectures');
    }
}
