<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffEmploymentPeriod extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['staff_id', 'employment_type_id', 'effective_from', 'effective_to'];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id', 'user_id');
    }

    public function employmentType(): BelongsTo
    {
        return $this->belongsTo(EmploymentType::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date'];
    }
}
