<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Booth extends Model
{
    use HasFactory;

    // 未使用のマスタだけ論理削除できる（MasterDeletionService）。管理者が「削除済み」から復元できる。
    use SoftDeletes;

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
    /** このブースを使えるメニュー（Task 11-28）。 */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'booth_service')->withTimestamps();
    }

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
