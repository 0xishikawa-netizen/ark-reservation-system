<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Accounting\RevenueContractKind;
use App\Enums\Accounting\RevenueRecognitionContractStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class RevenueRecognitionContract extends Model
{
    use HasFactory;

    protected $fillable = [
        'kind', 'ticket_wallet_id', 'membership_id', 'period_start', 'period_end',
        'source_checkout_line_id', 'source_payment_id', 'contract_amount', 'units',
        'currency', 'status', 'operation_key',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $contract): void {
            $original = (string) $contract->getRawOriginal('status');
            if (in_array($original, [RevenueRecognitionContractStatus::Closed->value, RevenueRecognitionContractStatus::Voided->value], true)) {
                throw new RuntimeException('終了済みの収益配賦契約は変更できません。');
            }
        });
        static::deleting(static function (): never {
            throw new RuntimeException('収益配賦契約は削除できません。');
        });
    }

    public function ticketWallet(): BelongsTo
    {
        return $this->belongsTo(TicketWallet::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(RevenueAllocation::class)->orderBy('allocation_no');
    }

    protected function casts(): array
    {
        return [
            'kind' => RevenueContractKind::class,
            'period_start' => 'date', 'period_end' => 'date', 'contract_amount' => 'integer',
            'units' => 'integer', 'status' => RevenueRecognitionContractStatus::class,
        ];
    }
}
