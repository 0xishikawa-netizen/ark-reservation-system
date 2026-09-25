<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class RevenueAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'revenue_recognition_contract_id', 'visit_id', 'visit_treatment_id',
        'ticket_reservation_usage_id', 'membership_reservation_usage_id', 'recognized_on',
        'amount', 'allocation_no', 'is_remainder', 'operation_key',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new RuntimeException('収益配賦実績は追記専用です。');
        });
        static::deleting(static function (): never {
            throw new RuntimeException('収益配賦実績は追記専用です。');
        });
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(RevenueRecognitionContract::class, 'revenue_recognition_contract_id');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    protected function casts(): array
    {
        return [
            'recognized_on' => 'date', 'amount' => 'integer', 'allocation_no' => 'integer',
            'is_remainder' => 'boolean',
        ];
    }
}
