<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** コース別の月間売上目標（Task 11-25）。 */
class CourseSalesTarget extends Model
{
    public const TYPES = ['ticket', 'membership', 'service'];

    protected $fillable = ['target_month', 'course_type', 'course_id', 'target_amount', 'target_count', 'updated_by'];

    protected function casts(): array
    {
        return ['target_month' => 'date', 'target_amount' => 'integer', 'target_count' => 'integer', 'course_id' => 'integer'];
    }
}
