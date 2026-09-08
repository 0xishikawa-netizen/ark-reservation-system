<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipPlan extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'price',
        'usage_count_per_period',
        'billing_interval',
        'stripe_price_id',
        'is_active',
        'sort_order',
    ];

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'price' => 'integer',
            'usage_count_per_period' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
