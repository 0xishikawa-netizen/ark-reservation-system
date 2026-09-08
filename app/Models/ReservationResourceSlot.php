<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Reservation\ResourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationResourceSlot extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'resource_type',
        'resource_id',
        'slot_start',
        'reservation_id',
    ];

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'slot_start' => 'datetime',
            'resource_type' => ResourceType::class,
        ];
    }
}
