<?php

declare(strict_types=1);

namespace App\Domain\Ticket;

use App\Enums\Ticket\TicketExpirationHoldPolicy;
use App\Enums\Ticket\TicketNoShowPolicy;
use App\Support\Settings\Settings;

final class TicketPolicyResolver
{
    public const ALLOWED_NO_SHOW = ['restore', 'consume'];

    public const ALLOWED_EXPIRATION_HOLD = ['preserve_hold'];

    public function __construct(private readonly Settings $settings) {}

    public function noShowPolicy(): TicketNoShowPolicy
    {
        $value = $this->settings->get('ticket.no_show_policy', 'restore');

        return is_string($value)
            ? TicketNoShowPolicy::tryFrom($value) ?? TicketNoShowPolicy::Restore
            : TicketNoShowPolicy::Restore;
    }

    public function expirationHoldPolicy(): TicketExpirationHoldPolicy
    {
        $value = $this->settings->get('ticket.expiration_hold_policy', 'preserve_hold');

        return is_string($value)
            ? TicketExpirationHoldPolicy::tryFrom($value) ?? TicketExpirationHoldPolicy::PreserveHold
            : TicketExpirationHoldPolicy::PreserveHold;
    }
}
