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
            TwoFactorAuthenticationConfirmed::class => 'onTwoFactorAuthenticationConfirmed',
        ];
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
