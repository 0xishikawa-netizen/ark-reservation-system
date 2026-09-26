<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Visit\VisitStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class Visit extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id', 'reservation_id', 'business_date', 'status', 'started_at', 'completed_at',
        'primary_staff_id', 'primary_staff_name_snapshot', 'visit_sequence',
        'first_visit_gender_snapshot', 'first_visit_age_years_snapshot',
        'future_reservation_exists_at_checkout', 'future_reservation_snapshot_at',
        'staff_requested_at_checkout', 'requested_staff_id_at_checkout', 'nominations_recorded_at', 'completion_operation_id',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $visit): void {
            if (in_array($visit->getRawOriginal('status'), [VisitStatus::Completed->value, VisitStatus::Voided->value], true)) {
                throw new RuntimeException('完了済み来店実績は変更できません。');
            }
        });
        static::deleting(static function (self $visit): void {
            if ($visit->status !== VisitStatus::Draft) {
                throw new RuntimeException('完了済み来店実績は削除できません。');
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'user_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function primaryStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'primary_staff_id', 'user_id');
    }

    public function nominations(): HasMany
    {
        return $this->hasMany(VisitStaffNomination::class);
    }

    public function treatments(): HasMany
    {
        return $this->hasMany(VisitTreatment::class)->orderBy('sort_order');
    }

    public function checkout(): HasOne
    {
        return $this->hasOne(Checkout::class);
    }

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'status' => VisitStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'visit_sequence' => 'integer',
            'first_visit_age_years_snapshot' => 'integer',
            'future_reservation_exists_at_checkout' => 'boolean',
            'future_reservation_snapshot_at' => 'datetime',
            'staff_requested_at_checkout' => 'boolean',
            'nominations_recorded_at' => 'datetime',
        ];
    }
}
