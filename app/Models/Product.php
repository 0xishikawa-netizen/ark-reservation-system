<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'price',
        'tax_category_id',
        'is_active',
        'sort_order',
    ];

    public function taxCategory(): BelongsTo
    {
        return $this->belongsTo(TaxCategory::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
