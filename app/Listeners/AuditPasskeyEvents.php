<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;

/**
 * Passkey 操作の監査（PLAN §13）。
 *
 * **credential（公開鍵・credential_id）・秘密情報を audit へ書かない。**
 * 記録するのは「誰が・いつ・どの端末名で」だけ。
 */
class AuditPasskeyEvents
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handleRegistered(PasskeyRegistered $event): void
    {
        $this->log('mfa.passkey.registered', $event->user, $event->passkey->name, (int) $event->passkey->id);
    }

    public function handleDeleted(PasskeyDeleted $event): void
    {
        $this->log('mfa.passkey.removed', $event->user, $event->passkey->name, (int) $event->passkey->id);
    }

    public function handleVerified(PasskeyVerified $event): void
    {
        $this->log('mfa.passkey.verified', $event->user, $event->passkey->name, (int) $event->passkey->id);
    }

    private function log(string $action, Authenticatable $user, string $name, int $passkeyId): void
    {
        // 端末名はユーザー入力。監査要約に載せるため長さを絞る。
        $safeName = mb_substr($name, 0, 60);

        $this->auditLogger->log(
            $action,
            null,
            "Passkey#{$passkeyId}（{$safeName}）user#{$user->getAuthIdentifier()}",
            $user,
        );
    }
}
