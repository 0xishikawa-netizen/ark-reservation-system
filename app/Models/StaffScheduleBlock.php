<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Schedule\ScheduleBlockType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffScheduleBlock extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'staff_id',
        'booth_id',
        'work_date',
        'start_at',
        'end_at',
        'type',
        'title',
        'note',
        'created_by',
    ];

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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'type' => ScheduleBlockType::class,
        ];
    }
}
