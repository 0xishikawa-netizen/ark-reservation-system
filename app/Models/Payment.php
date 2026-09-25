<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Payment\PaymentKind;
use App\Enums\Payment\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'customer_id',
        'reservation_id',
        'parent_payment_id',
        'kind',
        'provider',
        'payment_operation_id',
        'amount',
        'currency',
        'capture_method',
        'payment_expires_at',
        'stripe_payment_intent_id',
        'stripe_charge_id',
        'authorized_at',
        'paid_at',
        'voided_at',
        'refunded_amount',
        'failure_code',
        'failure_message',
        'needs_attention',
        'last_synced_at',
        'created_by',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'user_id');
    }

    public function parentPayment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_payment_id');
    }

    /** @return HasMany<Payment, $this> */
    public function addonPayments(): HasMany
    {
        return $this->hasMany(self::class, 'parent_payment_id');
    }

    /** @return HasMany<PaymentRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    /** @return HasMany<CheckoutTender, $this> */
    public function checkoutTenders(): HasMany
    {
        return $this->hasMany(CheckoutTender::class);
    }

    /** @return HasMany<RevenueRecognitionContract, $this> */
    public function revenueRecognitionContracts(): HasMany
    {
        return $this->hasMany(RevenueRecognitionContract::class, 'source_payment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => PaymentKind::class,
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'refunded_amount' => 'integer',
            'needs_attention' => 'boolean',
            'payment_expires_at' => 'datetime',
            'authorized_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }
}
