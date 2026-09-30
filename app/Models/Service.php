<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use HasFactory;

    // 未使用のマスタだけ論理削除できる（MasterDeletionService）。管理者が「削除済み」から復元できる。
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'duration_min',
        'price',
        'category',
        'analysis_category_id',
        'tax_category_id',
        'is_online_bookable',
        'requires_staff',
        'color',
        'is_active',
        'sort_order',
    ];

    public function analysisCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceAnalysisCategory::class, 'analysis_category_id');
    }

    public function taxCategory(): BelongsTo
    {
        return $this->belongsTo(TaxCategory::class);
    }

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

    /** @return HasMany<VisitTreatment, $this> */
    /** このメニューで使える具体的なブース（Task 11-28）。空なら全有効ブースが候補。 */
    public function booths(): BelongsToMany
    {
        return $this->belongsToMany(Booth::class, 'booth_service')->withTimestamps();
    }

    /** この施術を担当するのに必要な資格（Task 11-28）。全部を保有するスタッフだけが担当できる。 */
    public function qualifications(): BelongsToMany
    {
        return $this->belongsToMany(Qualification::class, 'qualification_service')->withTimestamps();
    }

    public function visitTreatments(): HasMany
    {
        return $this->hasMany(VisitTreatment::class);
    }

    /** @return HasMany<CheckoutLine, $this> */
    public function checkoutLines(): HasMany
    {
        return $this->hasMany(CheckoutLine::class);
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
