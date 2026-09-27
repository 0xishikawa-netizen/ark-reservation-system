<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Visit\VisitTreatmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class VisitTreatment extends Model
{
    use HasFactory;

    protected $fillable = [
        'visit_id', 'service_id', 'analysis_category_id', 'service_name_snapshot',
        'analysis_category_code_snapshot', 'analysis_category_name_snapshot', 'status',
        'actual_started_at', 'actual_ended_at', 'actual_minutes', 'sort_order', 'operation_key', 'booth_id',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $treatment): void {
            if ($treatment->getRawOriginal('status') !== VisitTreatmentStatus::Draft->value) {
                throw new RuntimeException('確定済みの施術実績は変更できません。');
            }
        });
        static::deleting(static function (self $treatment): void {
            if ($treatment->status !== VisitTreatmentStatus::Draft) {
                throw new RuntimeException('確定済みの施術実績は削除できません。');
            }
        });
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function analysisCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceAnalysisCategory::class);
    }

    public function staffAssignments(): HasMany
    {
        return $this->hasMany(VisitTreatmentStaff::class)->orderBy('sort_order');
    }

    protected function casts(): array
    {
        return [
            'status' => VisitTreatmentStatus::class,
            'actual_started_at' => 'datetime',
            'actual_ended_at' => 'datetime',
            'actual_minutes' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
