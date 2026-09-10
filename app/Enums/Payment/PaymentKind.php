<?php

declare(strict_types=1);

namespace App\Enums\Payment;

enum PaymentKind: string
{
    case Single = 'single';
    case SingleAddon = 'single_addon';
    case TicketPurchase = 'ticket_purchase';
    case MembershipInvoice = 'membership_invoice';
}
