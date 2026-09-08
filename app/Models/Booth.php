<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booth extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'sort_order',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('sort_order', function (Builder $builder): void {
            $builder->orderBy('sort_order');
        });
    }

    /** @param  Builder<Booth>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
