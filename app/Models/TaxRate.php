<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxRate extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['tax_category_id', 'rate_bps', 'effective_from', 'effective_to'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(TaxCategory::class, 'tax_category_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rate_bps' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }
}
