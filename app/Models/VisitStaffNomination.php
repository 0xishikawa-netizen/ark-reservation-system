<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Visit\VisitStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/** 来店単位・スタッフ単位の指名snapshot。完了済み来店では変更できない。 */
class VisitStaffNomination extends Model
{
    protected $fillable = ['visit_id', 'staff_id', 'staff_name_snapshot'];

    protected static function booted(): void
    {
        $guard = static function (self $nomination): void {
            if ($nomination->visit()->value('status') !== VisitStatus::Draft) {
                throw new RuntimeException('完了済み来店の指名は変更できません。');
            }
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id', 'user_id');
    }
}
