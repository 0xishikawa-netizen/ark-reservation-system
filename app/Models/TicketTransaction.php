<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Ticket\TicketTransactionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class TicketTransaction extends Model
{
    use HasFactory;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'ticket_wallet_id',
        'type',
        'delta',
        'reservation_id',
        'staff_id',
        'reason',
        'dedupe_key',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new RuntimeException('ticket_transactions は追記専用です。');
        });

        static::deleting(static function (): never {
            throw new RuntimeException('ticket_transactions は追記専用です。');
        });
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(TicketWallet::class, 'ticket_wallet_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id', 'user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => TicketTransactionType::class,
            'delta' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
