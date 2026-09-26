<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use Illuminate\Validation\ValidationException;

/**
 * 店頭会計の税額計算の唯一の実装。
 * 価格は税込（内税）で、税額は会計明細ごとに「税込額 × 税率 ÷ (1 + 税率)」を1円未満切り捨てる。
 * 確定した値はcheckout_linesへsnapshotされ、以後は再計算しない（集計はsnapshotが正本）。
 */
final class TaxAmountCalculator
{
    /** @return array{net_amount:int,tax_amount:int,gross_amount:int} */
    public function splitInclusive(int $grossAmount, ?int $rateBps): array
    {
        if ($grossAmount < 0) {
            throw ValidationException::withMessages(['gross_amount' => __('messages.checkout_entry.amount_invalid')]);
        }
        if ($rateBps === null || $rateBps < 0) {
            throw ValidationException::withMessages(['tax_rate' => __('messages.checkout_entry.tax_rate_missing')]);
        }
        $tax = intdiv($grossAmount * $rateBps, 10000 + $rateBps);

        return ['net_amount' => $grossAmount - $tax, 'tax_amount' => $tax, 'gross_amount' => $grossAmount];
    }
}
