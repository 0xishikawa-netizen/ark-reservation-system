<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 未ログイン予約の確認リンクを検証するためのトークン。
 *
 * URL に載せる selector と validator のうち、DB には validator のハッシュだけを保持する。
 */
class ReservationGuestToken extends Model
{
    use Prunable;

    /** @var list<string> */
    protected $fillable = [
        'reservation_id',
        'selector',
        'token_hash',
        'last_used_at',
        'expires_at',
    ];

    protected $hidden = ['token_hash'];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** 期限切れ直後の調査余地を残し、7日後に物理削除する。 */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now()->subDays(7));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
