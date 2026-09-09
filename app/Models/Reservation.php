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

class Reservation extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'customer_id',
        'service_id',
        'staff_id',
        'booth_id',
        'starts_at',
        'ends_at',
        'source',
        'payment_method',
        'payment_status',
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

    /** @return HasMany<ReservationProviderMapping, $this> */
    public function providerMappings(): HasMany
    {
        return $this->hasMany(ReservationProviderMapping::class);
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
            'attended_at' => 'datetime',
            'canceled_at' => 'datetime',
            'payment_expires_at' => 'datetime',
            'synced_at' => 'datetime',
            'status' => ReservationStatus::class,
            'source' => ReservationSource::class,
            'payment_method' => PaymentMethod::class,
            'payment_status' => PaymentStatus::class,
            'sync_status' => SyncStatus::class,
            'version' => 'integer',
        ];
    }
}
