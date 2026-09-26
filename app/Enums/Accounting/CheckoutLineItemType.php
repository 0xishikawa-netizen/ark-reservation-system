<?php

declare(strict_types=1);

namespace App\Enums\Accounting;

/** 会計明細の種別。施術・物販・回数券購入・月額・その他販売を区別する。 */
enum CheckoutLineItemType: string
{
    case Service = 'service';
    case Product = 'product';
    case Ticket = 'ticket';
    case Membership = 'membership';
    case Other = 'other';

    /** 支払配分・月計で物販（旧帳票K〜N）として扱う種別。 */
    public function isRetail(): bool
    {
        return $this === self::Product;
    }
}
