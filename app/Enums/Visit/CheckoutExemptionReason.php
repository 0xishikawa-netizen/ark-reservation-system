<?php

declare(strict_types=1);

namespace App\Enums\Visit;

/**
 * 会計を作らずに来店完了とする正当な理由（Task 11-27）。
 * 通常の有償施術は「来店・会計」で実施内容と会計を確定する。これ以外の理由では会計なし完了を認めない。
 */
enum CheckoutExemptionReason: string
{
    /** 無料施術（体験・サービス等）。 */
    case Free = 'free';
    /** オンライン等で事前決済済み。 */
    case Prepaid = 'prepaid';
    /** 回数券・月額の利用（購入時に売上計上済み）。 */
    case Entitlement = 'entitlement';

    public function label(): string
    {
        return __('messages.visit_completion.exemption_'.$this->value);
    }
}
