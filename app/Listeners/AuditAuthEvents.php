<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\Audit\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;

class AuditAuthEvents
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Logout::class => 'onLogout',
            Failed::class => 'onFailed',
            PasswordReset::class => 'onPasswordReset',
            Verified::class => 'onVerified',
            TwoFactorAuthenticationEnabled::class => 'onTwoFactorAuthenticationEnabled',
            TwoFactorAuthenticationConfirmed::class => 'onTwoFactorAuthenticationConfirmed',
        ];
    }

    /**
     * TOTP を有効化（新規 or 秘密鍵の再生成）したら、必ず新しいコードでの
     * 確認を要求する（`fortify.features` は confirm 必須）。
     *
     * 再生成時に `two_factor_confirmed_at` が残っていると、未確認の新しい秘密鍵が
     * 「確認済み」として扱われ、旧端末が使えなくなった利用者がロックアウトされる／
     * 攻撃者が自分の秘密鍵へ静かにローテーションできてしまう。ここで必ず null に戻す。
     */
    public function onTwoFactorAuthenticationEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        $user = $event->user;

        if (! is_object($user) || ! method_exists($user, 'forceFill')) {
            return;
        }

        if (($user->two_factor_confirmed_at ?? null) !== null) {
            $user->forceFill(['two_factor_confirmed_at' => null])->save();

            $this->auditLogger->log(
                'auth.two_factor_reset',
                summary: '二要素認証の秘密鍵を再生成（要再確認）: '.$this->email($user),
                actor: $user,
            );
        }
    }

    public function onLogin(Login $event): void
    {
        $this->auditLogger->log(
            'auth.login',
            summary: 'ログイン: '.$this->email($event->user),
            actor: $event->user,
        );
    }

    public function onLogout(Logout $event): void
    {
        $this->auditLogger->log(
            'auth.logout',
            summary: 'ログアウト: '.$this->email($event->user),
            actor: $event->user,
        );
    }

    public function onFailed(Failed $event): void
    {
        $email = $event->credentials['email'] ?? '';

        $this->auditLogger->log(
            'auth.failed',
            summary: 'ログイン失敗: '.(is_scalar($email) ? (string) $email : ''),
            actor: null,
        );
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        $this->auditLogger->log(
            'auth.password_reset',
            summary: 'パスワード再設定: '.$this->email($event->user),
            actor: $event->user,
        );
    }

    public function onVerified(Verified $event): void
    {
        $this->auditLogger->log(
            'auth.email_verified',
            summary: 'メール確認完了: '.$this->email($event->user),
            actor: $event->user,
        );
    }

    public function onTwoFactorAuthenticationConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->auditLogger->log(
            'auth.two_factor_enabled',
            summary: '二要素認証を有効化: '.$this->email($event->user),
            actor: $event->user,
        );
    }

    private function email(Authenticatable $actor): string
    {
        $email = data_get($actor, 'email');

        return is_scalar($email) ? (string) $email : '';
    }
}
