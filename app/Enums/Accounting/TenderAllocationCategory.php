<?php

declare(strict_types=1);

namespace App\Enums\Accounting;

/** 支払内訳の配分先。物販は旧月計表K〜N、それ以外（施術・回数券・月額・その他）はC〜Iに対応する。 */
enum TenderAllocationCategory: string
{
    case Treatment = 'treatment';
    case Retail = 'retail';

    public static function forLineType(string $itemType): self
    {
        return CheckoutLineItemType::tryFrom($itemType)?->isRetail() ? self::Retail : self::Treatment;
    }
}
