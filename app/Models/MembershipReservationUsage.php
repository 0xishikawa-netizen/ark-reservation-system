<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Membership\MembershipNoShowPolicy;
use App\Enums\Membership\MembershipReservationUsageStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MembershipReservationUsage extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'reservation_id',
        'membership_id',
        'period_start',
        'no_show_policy',
        'status',
        'reserved_at',
        'released_at',
        'consumed_at',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function revenueAllocation(): HasOne
    {
        return $this->hasOne(RevenueAllocation::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => MembershipReservationUsageStatus::class,
            'no_show_policy' => MembershipNoShowPolicy::class,
            'period_start' => 'date',
            'reserved_at' => 'datetime',
            'released_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
