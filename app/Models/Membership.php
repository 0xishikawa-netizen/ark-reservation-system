<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Membership\MembershipStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Membership extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'customer_id',
        'membership_plan_id',
        'stripe_subscription_id',
        'membership_operation_id',
        'pending_operation',
        'status',
        'current_period_start',
        'current_period_end',
        'cancel_at_period_end',
        'grace_until',
        'period_available',
        'started_at',
        'canceled_at',
        'last_synced_at',
        'needs_attention',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'user_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    /** @return HasMany<MembershipUsageTransaction, $this> */
    public function usageTransactions(): HasMany
    {
        return $this->hasMany(MembershipUsageTransaction::class);
    }

    /** @return HasMany<MembershipReservationUsage, $this> */
    public function reservationUsages(): HasMany
    {
        return $this->hasMany(MembershipReservationUsage::class);
    }

    /** @param  Builder<Membership>  $query */
    public function scopeBookable(Builder $query): void
    {
        $query->whereIn('status', [
            MembershipStatus::Active->value,
            MembershipStatus::Grace->value,
            MembershipStatus::Canceling->value,
        ]);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'current_period_start' => 'date',
            'current_period_end' => 'date',
            'cancel_at_period_end' => 'boolean',
            'grace_until' => 'datetime',
            'period_available' => 'integer',
            'started_at' => 'datetime',
            'canceled_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'needs_attention' => 'boolean',
        ];
    }
}
