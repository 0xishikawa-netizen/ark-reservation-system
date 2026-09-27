<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\PaymentStatus;
use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Reservation\SyncStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Reservation extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'customer_id',
        'service_id',
        'staff_id',
        'is_staff_requested',
        'booth_id',
        'starts_at',
        'ends_at',
        'buffer_min',
        'source',
        'inflow_channel',
        'payment_method',
        'payment_status',
        'final_amount',
        'payment_expires_at',
        'status',
        'attended_at',
        'canceled_at',
        'cancel_reason',
        'external_provider',
        'external_reservation_id',
        'sync_status',
        'synced_at',
        'sync_error',
        'version',
        'notes',
        'created_by',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'user_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id', 'user_id');
    }

    /** 延長などで追加した予定構成（Task 11-29）。 */
    public function segments(): HasMany
    {
        return $this->hasMany(ReservationSegment::class)->orderBy('sort_order')->orderBy('id');
    }

    /** 予約が確保している施術分数（延長を含み、終了後インターバルを除く）。 */
    public function bookedMinutes(): int
    {
        return max(1, (int) $this->starts_at->diffInMinutes($this->ends_at) - (int) $this->buffer_min);
    }

    public function booth(): BelongsTo
    {
        return $this->belongsTo(Booth::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ReservationResourceSlot, $this> */
    public function resourceSlots(): HasMany
    {
        return $this->hasMany(ReservationResourceSlot::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function visit(): HasOne
    {
        return $this->hasOne(Visit::class);
    }

    /** @return HasMany<ReservationProviderMapping, $this> */
    public function providerMappings(): HasMany
    {
        return $this->hasMany(ReservationProviderMapping::class);
    }

    /** @return HasMany<ReservationGuestToken, $this> */
    public function guestTokens(): HasMany
    {
        return $this->hasMany(ReservationGuestToken::class);
    }

    /** @param  Builder<Reservation>  $query */
    public function scopeForDate(Builder $query, CarbonInterface $date): void
    {
        $query->whereDate('starts_at', $date->format('Y-m-d'));
    }

    /** @param  Builder<Reservation>  $query */
    public function scopeForStaff(Builder $query, int $staffId): void
    {
        $query->where('staff_id', $staffId);
    }

    /** @param  Builder<Reservation>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [
            ReservationStatus::PendingPayment->value,
            ReservationStatus::PendingExternalSync->value,
            ReservationStatus::Confirmed->value,
        ]);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'buffer_min' => 'integer',
            'attended_at' => 'datetime',
            'canceled_at' => 'datetime',
            'payment_expires_at' => 'datetime',
            'final_amount' => 'integer',
            'synced_at' => 'datetime',
            'status' => ReservationStatus::class,
            'source' => ReservationSource::class,
            'payment_method' => PaymentMethod::class,
            'payment_status' => PaymentStatus::class,
            'sync_status' => SyncStatus::class,
            'version' => 'integer',
            'is_staff_requested' => 'boolean',
        ];
    }
}
