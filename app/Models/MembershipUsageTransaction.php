<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Membership\MembershipUsageType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * 追記専用の利用権台帳。UPDATE / DELETE を禁止する（訂正は新しい行で表現）。
 */
class MembershipUsageTransaction extends Model
{
    use HasFactory;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'membership_id',
        'period_start',
        'type',
        'delta',
        'reservation_id',
        'staff_id',
        'reason',
        'dedupe_key',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new RuntimeException('membership_usage_transactions は追記専用です。');
        });

        static::deleting(static function (): never {
            throw new RuntimeException('membership_usage_transactions は追記専用です。');
        });
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id', 'user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => MembershipUsageType::class,
            'delta' => 'integer',
            'period_start' => 'date',
            'created_at' => 'datetime',
        ];
    }
}
