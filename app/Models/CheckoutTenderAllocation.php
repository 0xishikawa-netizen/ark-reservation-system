<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Accounting\CheckoutStatus;
use App\Enums\Accounting\TenderAllocationCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/** 支払内訳の施術等／物販への明示配分。下書き会計でのみ変更できる。 */
class CheckoutTenderAllocation extends Model
{
    protected $fillable = ['checkout_tender_id', 'allocation_category', 'amount'];

    protected static function booted(): void
    {
        $guard = static function (self $allocation): void {
            $status = Checkout::query()->whereKey(
                CheckoutTender::query()->whereKey($allocation->checkout_tender_id)->value('checkout_id'),
            )->value('status');
            if ($status !== CheckoutStatus::Draft) {
                throw new RuntimeException('確定済み会計の支払配分は変更できません。');
            }
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(CheckoutTender::class, 'checkout_tender_id');
    }

    protected function casts(): array
    {
        return ['allocation_category' => TenderAllocationCategory::class, 'amount' => 'integer'];
    }
}
