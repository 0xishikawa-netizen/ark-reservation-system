<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Accounting\CheckoutStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class CheckoutLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'checkout_id', 'item_type', 'visit_treatment_id', 'service_id', 'product_id',
        'ticket_product_id', 'membership_plan_id', 'item_name_snapshot', 'quantity', 'unit_amount',
        'tax_category_id', 'tax_category_code_snapshot', 'tax_category_name_snapshot', 'tax_rate_bps',
        'net_amount', 'tax_amount', 'gross_amount', 'is_staff_allocatable', 'sort_order', 'operation_key',
    ];

    protected static function booted(): void
    {
        $guard = static function (self $line): void {
            if ($line->checkout()->value('status') !== CheckoutStatus::Draft) {
                throw new RuntimeException('確定済み会計の明細は変更できません。');
            }
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(StaffRevenueAllocation::class);
    }

    public function taxCategory(): BelongsTo
    {
        return $this->belongsTo(TaxCategory::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer', 'unit_amount' => 'integer', 'tax_rate_bps' => 'integer',
            'net_amount' => 'integer', 'tax_amount' => 'integer', 'gross_amount' => 'integer',
            'is_staff_allocatable' => 'boolean', 'sort_order' => 'integer',
        ];
    }
}
