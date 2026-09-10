<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 外部 IdP（現在は Google のみ）と ARK ユーザーの identity マッピング。
 *
 * token 類は保持しない。ログインに必要な最小データのみ。
 */
class UserSocialAccount extends Model
{
    public const PROVIDER_GOOGLE = 'google';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'provider_email',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
