<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Visit\VisitTreatmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class VisitTreatmentStaff extends Model
{
    use HasFactory;

    protected $table = 'visit_treatment_staff';

    protected $fillable = [
        'visit_treatment_id', 'staff_id', 'staff_name_snapshot', 'actual_started_at',
        'actual_ended_at', 'actual_minutes', 'sort_order',
    ];

    protected static function booted(): void
    {
        $guard = static function (self $assignment): void {
            if ($assignment->treatment()->value('status') !== VisitTreatmentStatus::Draft) {
                throw new RuntimeException('確定済み施術の担当実績は変更できません。');
            }
        };
        static::updating($guard);
        static::deleting($guard);
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(VisitTreatment::class, 'visit_treatment_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id', 'user_id');
    }

    protected function casts(): array
    {
        return [
            'actual_started_at' => 'datetime',
            'actual_ended_at' => 'datetime',
            'actual_minutes' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
