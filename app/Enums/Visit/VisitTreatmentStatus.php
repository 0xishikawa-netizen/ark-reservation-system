<?php

declare(strict_types=1);

namespace App\Enums\Visit;

enum VisitTreatmentStatus: string
{
    case Draft = 'draft';
    case Completed = 'completed';
    case Voided = 'voided';
}
