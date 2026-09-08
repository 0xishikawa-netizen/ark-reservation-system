<?php

declare(strict_types=1);

namespace App\Actions\Ticket;

use App\Domain\Ticket\TicketPolicyResolver;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\Settings;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateTicketPolicy
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function execute(
        string $noShowPolicy,
        string $expirationHoldPolicy,
        string $reason,
        Authenticatable $actor,
    ): void {
        if (! in_array($noShowPolicy, TicketPolicyResolver::ALLOWED_NO_SHOW, true)) {
            throw ValidationException::withMessages([
                'no_show_policy' => '不正なポリシー値です。',
            ]);
        }

        if (! in_array($expirationHoldPolicy, TicketPolicyResolver::ALLOWED_EXPIRATION_HOLD, true)) {
            throw ValidationException::withMessages([
                'expiration_hold_policy' => '不正なポリシー値です。',
            ]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => '理由は必須です。',
            ]);
        }

        DB::transaction(function () use ($noShowPolicy, $expirationHoldPolicy, $reason, $actor): void {
            $oldNoShow = (string) $this->settings->get('ticket.no_show_policy', 'restore');
            $oldExpiration = (string) $this->settings->get(
                'ticket.expiration_hold_policy',
                'preserve_hold',
            );

            if ($oldNoShow !== $noShowPolicy) {
                $this->settings->set('ticket.no_show_policy', $noShowPolicy, 'string');
                $this->auditLogger->log(
                    'ticket.policy.no_show.changed',
                    null,
                    "ticket.no_show_policy: {$oldNoShow} → {$noShowPolicy}（理由: {$reason}）",
                    $actor,
                );
            }

            if ($oldExpiration !== $expirationHoldPolicy) {
                $this->settings->set(
                    'ticket.expiration_hold_policy',
                    $expirationHoldPolicy,
                    'string',
                );
                $this->auditLogger->log(
                    'ticket.policy.expiration_hold.changed',
                    null,
                    "ticket.expiration_hold_policy: {$oldExpiration} → {$expirationHoldPolicy}（理由: {$reason}）",
                    $actor,
                );
            }
        });
    }
}
