<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Payment\RefundStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRefund extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'payment_id',
        'refund_operation_id',
        'amount',
        'reason',
        'stripe_refund_id',
        'failure_code',
        'failure_message',
        'created_by',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'amount' => 'integer',
        ];
    }
}
