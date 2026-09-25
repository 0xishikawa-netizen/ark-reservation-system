<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketProduct extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'total_count',
        'price',
        'tax_category_id',
        'validity_days',
        'is_active',
        'sort_order',
    ];

    public function taxCategory(): BelongsTo
    {
        return $this->belongsTo(TaxCategory::class);
    }

    /** @return HasMany<TicketWallet, $this> */
    public function wallets(): HasMany
    {
        return $this->hasMany(TicketWallet::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
