<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * 施術に必要な資格（はり師など。Task 11-28）。削除せず無効化・並び替えで管理する。
 * スタッフの保有資格と、メニューが必要とする資格の両方から参照する。
 */
class Qualification extends Model
{
    protected $fillable = ['code', 'name', 'is_active', 'sort_order'];

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class, 'qualification_staff', 'qualification_id', 'staff_id', 'id', 'user_id')->withTimestamps();
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'qualification_service')->withTimestamps();
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }
}
