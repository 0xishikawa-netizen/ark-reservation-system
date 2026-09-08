<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketProduct extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'total_count',
        'price',
        'validity_days',
        'is_active',
        'sort_order',
    ];

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
