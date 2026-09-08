<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Models\Payment;

/**
 * 顧客の決済画面へ渡す最小限の情報。
 *
 * client_secret はここでのみ運ばれ、DB へ保存もログ出力もしない（PLAN §9 / phase-05 §6-2）。
 */
final readonly class CheckoutSession
{
    public function __construct(
        public Payment $payment,
        public ?string $clientSecret,
    ) {}
}
