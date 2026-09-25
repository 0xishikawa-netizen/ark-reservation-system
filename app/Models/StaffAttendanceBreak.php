<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StaffAttendanceBreak extends Model
{
    protected $fillable = ['staff_attendance_id', 'start_at', 'end_at', 'type', 'note'];

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(StaffAttendance::class, 'staff_attendance_id');
    }

    protected function casts(): array
    {
        return ['start_at' => 'datetime', 'end_at' => 'datetime'];
    }
}
