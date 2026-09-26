<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Accounting\CheckoutStatus;
use App\Enums\Accounting\CheckoutTenderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class CheckoutTender extends Model
{
    use HasFactory;

    protected $fillable = [
        'checkout_id', 'payment_method_id', 'payment_id', 'amount', 'status',
        'external_reference', 'received_at', 'operation_key',
    ];

    protected static function booted(): void
    {
        $guard = static function (self $tender): void {
            if ($tender->checkout()->value('status') !== CheckoutStatus::Draft) {
                throw new RuntimeException('確定済み会計の支払明細は変更できません。');
            }
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CheckoutTenderAllocation::class);
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    protected function casts(): array
    {
        return ['amount' => 'integer', 'status' => CheckoutTenderStatus::class, 'received_at' => 'datetime'];
    }
}
