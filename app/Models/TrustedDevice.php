<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 「この端末を信頼する」による TOTP チャレンジ省略の記録。
 *
 * selector（公開のルックアップキー）+ token_hash（検証用シークレットのハッシュ）の
 * 組で照合する（remember-me トークンと同じ設計）。平文トークンは保持しない。
 */
class TrustedDevice extends Model
{
    use Prunable;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'selector',
        'token_hash',
        'user_agent',
        'last_used_at',
        'expires_at',
    ];

    /** token_hash は API 表現へ出さない。 */
    protected $hidden = ['token_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** 期限切れから一定期間経ったものだけ物理削除する。 */
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
