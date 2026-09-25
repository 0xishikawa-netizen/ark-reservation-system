<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Accounting\CheckoutStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class StaffRevenueAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'checkout_line_id', 'staff_id', 'visit_treatment_staff_id', 'staff_name_snapshot',
        'basis_minutes', 'allocated_amount', 'operation_key',
    ];

    protected static function booted(): void
    {
        $guard = static function (self $allocation): void {
            if ($allocation->line()->firstOrFail()->checkout()->value('status') !== CheckoutStatus::Draft) {
                throw new RuntimeException('確定済み会計のスタッフ配分は変更できません。');
            }
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(CheckoutLine::class, 'checkout_line_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id', 'user_id');
    }

    public function treatmentStaff(): BelongsTo
    {
        return $this->belongsTo(VisitTreatmentStaff::class, 'visit_treatment_staff_id');
    }

    protected function casts(): array
    {
        return ['basis_minutes' => 'integer', 'allocated_amount' => 'integer'];
    }
}
