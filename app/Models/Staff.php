<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Security\PiiHasher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Staff extends Model
{
    use HasFactory;

    protected $table = 'staff';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'display_name',
        'color',
        'is_bookable',
        'sort_order',
        'phone',
        'phone_verified_at',
    ];

    /** @var list<string> */
    protected $hidden = ['phone', 'phone_hmac'];

    protected static function booted(): void
    {
        // 平文の検索コピーを持たず、正規化値の keyed HMAC で等価検索する（PLAN §13）。
        static::saving(function (self $staff): void {
            if (! $staff->isDirty('phone')) {
                return;
            }

            $staff->phone_hmac = PiiHasher::phoneHmac($staff->phone);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<StaffShift, $this> */
    public function shifts(): HasMany
    {
        return $this->hasMany(StaffShift::class, 'staff_id', 'user_id');
    }

    /** @return HasMany<StaffShiftTemplate, $this> */
    public function shiftTemplates(): HasMany
    {
        return $this->hasMany(StaffShiftTemplate::class, 'staff_id', 'user_id');
    }

    /** @return HasMany<StaffShiftException, $this> */
    public function shiftExceptions(): HasMany
    {
        return $this->hasMany(StaffShiftException::class, 'staff_id', 'user_id');
    }

    /** @return HasMany<StaffEmploymentPeriod, $this> */
    public function employmentPeriods(): HasMany
    {
        return $this->hasMany(StaffEmploymentPeriod::class, 'staff_id', 'user_id');
    }

    /** @return HasMany<Visit, $this> */
    public function primaryVisits(): HasMany
    {
        return $this->hasMany(Visit::class, 'primary_staff_id', 'user_id');
    }

    /** @return HasMany<VisitTreatmentStaff, $this> */
    public function treatmentAssignments(): HasMany
    {
        return $this->hasMany(VisitTreatmentStaff::class, 'staff_id', 'user_id');
    }

    /** @return HasMany<StaffRevenueAllocation, $this> */
    public function revenueAllocations(): HasMany
    {
        return $this->hasMany(StaffRevenueAllocation::class, 'staff_id', 'user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_bookable' => 'boolean',
            'phone' => 'encrypted',
            'phone_verified_at' => 'datetime',
        ];
    }
}
