<?php

declare(strict_types=1);

namespace App\Enums\Reporting;

enum SalesBasis: string
{
    case PaymentDate = 'payment_date';
    case TreatmentDate = 'treatment_date';
}
