<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Accounting\CheckoutStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class Checkout extends Model
{
    use HasFactory;

    protected $fillable = [
        'visit_id', 'customer_id', 'sale_date', 'status', 'subtotal_amount', 'tax_amount', 'total_amount', 'currency',
        'finalized_at', 'voided_at', 'void_reason', 'operation_id',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $checkout): void {
            $original = (string) $checkout->getRawOriginal('status');
            if ($original === CheckoutStatus::Voided->value) {
                throw new RuntimeException('取消済み会計は変更できません。');
            }
            if ($original === CheckoutStatus::Finalized->value) {
                $allowed = ['status', 'voided_at', 'void_reason', 'updated_at'];
                if ($checkout->status !== CheckoutStatus::Voided || array_diff(array_keys($checkout->getDirty()), $allowed) !== []) {
                    throw new RuntimeException('確定済み会計は取消以外で変更できません。');
                }
            }
        });
        static::deleting(static function (self $checkout): void {
            if ($checkout->status !== CheckoutStatus::Draft) {
                throw new RuntimeException('確定済み会計は削除できません。');
            }
        });
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CheckoutLine::class)->orderBy('sort_order');
    }

    public function tenders(): HasMany
    {
        return $this->hasMany(CheckoutTender::class);
    }

    protected function casts(): array
    {
        return [
            'status' => CheckoutStatus::class,
            'subtotal_amount' => 'integer',
            'tax_amount' => 'integer',
            'total_amount' => 'integer',
            'sale_date' => 'date',
            'finalized_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
