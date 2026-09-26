<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class DailyBusinessNote extends Model
{
    /** @var list<string> */
    protected $fillable = ['business_date', 'business_condition', 'reflection', 'created_by', 'updated_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['business_date' => 'date'];
    }
}
