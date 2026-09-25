<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxCategory extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'is_active', 'sort_order'];

    /** @return HasMany<TaxRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(TaxRate::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }
}
