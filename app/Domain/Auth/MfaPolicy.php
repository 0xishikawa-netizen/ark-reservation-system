<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Models\User;

/**
 * スタッフ系ユーザーの MFA 要件判定を集約する唯一の入口。
 *
 * middleware / UI / テストは必ずここを通す。
 *
 * Phase 9.6: Passkey（WebAuthn）を撤去。MFA 手段は **6 桁 TOTP 一本**。
 * SMS は SIM スワップ耐性が無いため、これ単独では要件を満たさない補助手段。
 * 認証フローは「メール/パスワード（または Google）→ 主認証成功 → 必要なら TOTP チャレンジ」。
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
     * MFA 要件を満たしているか（確認済み TOTP を持つ）。
     */
    public function isSatisfiedBy(User $user): bool
    {
        return $this->hasConfirmedTotp($user);
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
     * 主たる MFA 手段の数（現在は TOTP のみ、0 か 1）。
     */
    public function primaryMethodCount(User $user): int
    {
        return $this->hasConfirmedTotp($user) ? 1 : 0;
    }

    /**
     * TOTP を無効化すると MFA がゼロになる（＝業務ロールでは禁止）か。
     */
    public function wouldRemoveLastMethod(User $user): bool
    {
        return $this->isRequiredFor($user) && $this->primaryMethodCount($user) <= 1;
    }

    /**
     * 画面表示用の要約。**secret / recovery code / 電話番号平文を含めない。**
     *
     * @return array{
     *     required: bool,
     *     satisfied: bool,
     *     totp_confirmed: bool,
     *     phone_verified: bool
     * }
     */
    public function summaryFor(User $user): array
    {
        return [
            'required' => $this->isRequiredFor($user),
            'satisfied' => $this->isSatisfiedBy($user),
            'totp_confirmed' => $this->hasConfirmedTotp($user),
            'phone_verified' => $this->hasVerifiedPhone($user),
        ];
    }
}
