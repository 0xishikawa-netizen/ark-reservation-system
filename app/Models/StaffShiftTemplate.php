<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * スタッフの基本シフト（曜日ごとの通常勤務時間）。
 */
class StaffShiftTemplate extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'staff_id',
        'weekday',
        'start_at',
        'end_at',
        'is_active',
    ];

    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id', 'user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
