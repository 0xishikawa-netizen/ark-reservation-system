<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Service extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'duration_min',
        'price',
        'category',
        'is_online_bookable',
        'requires_staff',
        'color',
        'is_active',
        'sort_order',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('sort_order', function (Builder $builder): void {
            $builder->orderBy('sort_order');
        });
    }

    /** @return BelongsToMany<Staff, $this> */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(
            Staff::class,
            'service_staff',
            'service_id',
            'staff_id',
            'id',
            'user_id',
        );
    }

    /** @param  Builder<Service>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'duration_min' => 'integer',
            'price' => 'integer',
            'is_online_bookable' => 'boolean',
            'requires_staff' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
