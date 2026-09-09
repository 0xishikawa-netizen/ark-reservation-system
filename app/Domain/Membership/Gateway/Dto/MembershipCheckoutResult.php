<?php

declare(strict_types=1);

namespace App\Domain\Membership\Gateway\Dto;

use App\Models\Membership;

/**
 * 顧客の利用権申込 1 回分の結果。
 *
 * - requiresConfirmation=true: 初回 invoice が 3DS/SCA を要求している。client は
 *   clientSecret を使って Stripe.js で認証を完了し、その後サーバー sync を呼ぶ。
 * - requiresConfirmation=false: subscription が即 active（認証不要）。clientSecret は null。
 *
 * clientSecret は PaymentIntent の client_secret（ブラウザ用・secret key ではない）。
 * これ以外の Stripe internal を client へ渡さない。
 */
final readonly class MembershipCheckoutResult
{
    public function __construct(
        public Membership $membership,
        public bool $requiresConfirmation,
        public ?string $clientSecret = null,
    ) {}
}
