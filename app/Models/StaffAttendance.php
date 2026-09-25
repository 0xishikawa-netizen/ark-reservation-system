<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StaffAttendance extends Model
{
    protected $fillable = ['staff_id', 'business_date', 'clock_in_at', 'clock_out_at', 'status', 'note'];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id', 'user_id');
    }

    public function breaks(): HasMany
    {
        return $this->hasMany(StaffAttendanceBreak::class);
    }

    protected function casts(): array
    {
        return ['business_date' => 'date', 'clock_in_at' => 'datetime', 'clock_out_at' => 'datetime'];
    }
}
