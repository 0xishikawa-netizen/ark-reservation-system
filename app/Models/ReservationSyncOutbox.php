<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Integration\Enum\OutboxStatus;
use App\Domain\Integration\Enum\SyncOperation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationSyncOutbox extends Model
{
    protected $table = 'reservation_sync_outbox';

    /** @var list<string> */
    protected $fillable = [
        'provider', 'reservation_id', 'operation', 'idempotency_key', 'payload_json',
        'status', 'attempts', 'available_at', 'locked_at', 'locked_by',
        'last_error_category', 'last_error_code', 'correlation_id', 'completed_at',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'status' => OutboxStatus::class,
            'operation' => SyncOperation::class,
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'locked_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
