<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Integration\Enum\MappingSyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationProviderMapping extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'provider', 'reservation_id', 'external_reservation_id', 'external_customer_id',
        'fingerprint', 'external_updated_at', 'external_version', 'last_synced_at', 'last_seen_at', 'sync_status',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'external_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'sync_status' => MappingSyncStatus::class,
        ];
    }
}
