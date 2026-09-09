<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Integration\Enum\ConflictStatus;
use App\Domain\Integration\Enum\ConflictType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationSyncConflict extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'provider', 'reservation_id', 'external_reservation_id_masked', 'external_ref_hash', 'conflict_type',
        'ark_fingerprint', 'external_fingerprint', 'detected_at',
        'status', 'resolution', 'resolved_at', 'resolved_by',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'conflict_type' => ConflictType::class,
            'status' => ConflictStatus::class,
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
