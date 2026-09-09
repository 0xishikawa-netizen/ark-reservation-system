<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Integration\Enum\SyncDirection;
use App\Domain\Integration\Enum\SyncEventStatus;
use App\Domain\Integration\Enum\SyncOperation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * 追記専用。UPDATE/DELETE 禁止（Phase 4/6 の台帳と同手法）。
 */
class ReservationSyncEvent extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'provider', 'direction', 'operation', 'reservation_id', 'external_reservation_id_masked',
        'correlation_id', 'idempotency_key', 'status', 'attempt',
        'error_category', 'safe_error_code', 'started_at', 'completed_at', 'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new RuntimeException('reservation_sync_events は追記専用です。');
        });
        static::deleting(static function (): never {
            throw new RuntimeException('reservation_sync_events は追記専用です。');
        });
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'direction' => SyncDirection::class,
            'operation' => SyncOperation::class,
            'status' => SyncEventStatus::class,
            'attempt' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
