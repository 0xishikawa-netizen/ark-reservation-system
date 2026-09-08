<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Ticket\TicketNoShowPolicy;
use App\Enums\Ticket\TicketReservationUsageStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketReservationUsage extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'reservation_id',
        'ticket_wallet_id',
        'no_show_policy',
        'status',
        'held_at',
        'released_at',
        'consumed_at',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(TicketWallet::class, 'ticket_wallet_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TicketReservationUsageStatus::class,
            'no_show_policy' => TicketNoShowPolicy::class,
            'held_at' => 'datetime',
            'released_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
