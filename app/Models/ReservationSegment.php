<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 予約時点の予定構成（Task 11-29）。延長で追加した施術（例：はり30分）を予約メニューとは別に持つ。
 * 実績の正本は visit_treatments。
 */
class ReservationSegment extends Model
{
    protected $fillable = ['reservation_id', 'service_id', 'minutes', 'kind', 'sort_order', 'created_by'];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    protected function casts(): array
    {
        return ['minutes' => 'integer', 'sort_order' => 'integer'];
    }
}
