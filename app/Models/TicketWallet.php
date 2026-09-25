<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Ticket\TicketWalletStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TicketWallet extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'customer_id',
        'ticket_product_id',
        'purchased_count',
        'balance',
        'expires_at',
        'status',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'user_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(TicketProduct::class, 'ticket_product_id');
    }

    /** @return HasMany<TicketTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(TicketTransaction::class);
    }

    /** @return HasMany<TicketReservationUsage, $this> */
    public function reservationUsages(): HasMany
    {
        return $this->hasMany(TicketReservationUsage::class);
    }

    public function revenueRecognitionContract(): HasOne
    {
        return $this->hasOne(RevenueRecognitionContract::class);
    }

    /** @param  Builder<TicketWallet>  $query */
    public function scopeActive(Builder $query): void
    {
        $query
            ->where('status', TicketWalletStatus::Active->value)
            ->where('expires_at', '>=', today()->toDateString());
    }

    /** @param  Builder<TicketWallet>  $query */
    public function scopeFefo(Builder $query): void
    {
        $query
            ->orderBy('expires_at')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TicketWalletStatus::class,
            'expires_at' => 'date',
            'balance' => 'integer',
            'purchased_count' => 'integer',
        ];
    }
}
