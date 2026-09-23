<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 例外日。基本シフトと異なる日だけを表現する（休み / 時間変更）。
 * 例外日がある (staff_id, exception_date) は自動生成の対象外。
 */
class StaffShiftException extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'staff_id',
        'exception_date',
        'is_off',
        'note',
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
            'exception_date' => 'date',
            'is_off' => 'boolean',
        ];
    }
}
