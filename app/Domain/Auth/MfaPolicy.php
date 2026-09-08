<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Models\User;

/**
 * スタッフ系ユーザーの MFA 要件判定を集約する唯一の入口（PLAN §12）。
 *
 * middleware / UI / テストは必ずここを通す。
 * `users.two_factor_confirmed_at !== null` を各所で直接判定すると、
 * Passkey だけを登録したユーザーが「MFA 未設定」と誤判定され全員がロックアウトされる。
 *
 * 認証強度: Passkey > TOTP > SMS OTP。
 * **SMS は単独では要件を満たさない**（SIM スワップ耐性が無いため補助手段に限定する）。
 */
final class MfaPolicy
{
    /** MFA を必須とするロール。customer には課さない。 */
    public const REQUIRED_ROLES = ['staff', 'manager', 'admin'];

    /**
     * このユーザーに MFA が必要か。
     */
    public function isRequiredFor(?User $user): bool
    {
        return $user?->hasAnyRole(self::REQUIRED_ROLES) === true;
    }

    /**
     * MFA 要件を満たしているか（有効な「主」手段を 1 つ以上持つ）。
     */
    public function isSatisfiedBy(User $user): bool
    {
        return $this->hasPasskey($user) || $this->hasConfirmedTotp($user);
    }

    /**
     * setup 画面へ誘導すべきか。
     */
    public function needsSetup(?User $user): bool
    {
        return $user !== null
            && $this->isRequiredFor($user)
            && ! $this->isSatisfiedBy($user);
    }

    public function hasPasskey(User $user): bool
    {
        return $user->passkeys()->exists();
    }

    public function hasConfirmedTotp(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null;
    }

    /**
     * SMS OTP をフォールバックとして使えるか。
     *
     * **これだけでは `isSatisfiedBy()` を満たさない。**
     */
    public function hasVerifiedPhone(User $user): bool
    {
        return $user->staff?->phone_verified_at !== null;
    }

    /**
     * 主たる MFA 手段の数。**最後の 1 つを削除させないための判定に使う。**
     */
    public function primaryMethodCount(User $user): int
    {
        return $user->passkeys()->count() + ($this->hasConfirmedTotp($user) ? 1 : 0);
    }

    /**
     * この Passkey を削除すると MFA がゼロになるか。
     */
    public function wouldRemoveLastMethod(User $user): bool
    {
        return $this->primaryMethodCount($user) <= 1;
    }

    /**
     * Passkey 登録を促すべきか（TOTP のみのユーザーへの移行導線）。
     */
    public function shouldPromotePasskey(User $user): bool
    {
        return $this->isRequiredFor($user) && ! $this->hasPasskey($user);
    }

    /**
     * 画面表示用の要約。**secret / credential / 電話番号平文を含めない。**
     *
     * @return array{
     *     required: bool,
     *     satisfied: bool,
     *     passkey_count: int,
     *     totp_confirmed: bool,
     *     phone_verified: bool,
     *     should_promote_passkey: bool
     * }
     */
    public function summaryFor(User $user): array
    {
        return [
            'required' => $this->isRequiredFor($user),
            'satisfied' => $this->isSatisfiedBy($user),
            'passkey_count' => $user->passkeys()->count(),
            'totp_confirmed' => $this->hasConfirmedTotp($user),
            'phone_verified' => $this->hasVerifiedPhone($user),
            'should_promote_passkey' => $this->shouldPromotePasskey($user),
        ];
    }
}
