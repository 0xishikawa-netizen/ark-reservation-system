<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReservationProviderSyncState extends Model
{
    protected $table = 'reservation_provider_sync_state';

    /** @var list<string> */
    protected $fillable = [
        'provider', 'last_inbound_at', 'last_inbound_cursor',
        'last_outbound_at', 'last_reconcile_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
            'last_reconcile_at' => 'datetime',
        ];
    }
}
