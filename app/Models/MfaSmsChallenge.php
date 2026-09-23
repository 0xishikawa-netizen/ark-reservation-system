<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SMS OTP チャレンジ。**平文 OTP・平文電話番号を保持しない。**
 */
class MfaSmsChallenge extends Model
{
    use Prunable;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'purpose',
        'phone_hmac',
        'code_hash',
        'expires_at',
        'sent_at',
        'ip',
    ];

    /** code_hash は API 表現へ出さない。 */
    protected $hidden = ['code_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** 技術データのため保持期間つきで削除する（PLAN §6）。 */
    public function prunable(): Builder
    {
        return static::query()->where(
            'created_at',
            '<',
            now()->subDays((int) config('mfa.sms.retention_days', 7)),
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'sent_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
