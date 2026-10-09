<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Ticket\UpdateTicketPolicy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateTicketPolicyRequest;
use App\Support\Settings\Settings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class TicketPolicySettingsController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Admin/Settings/Tickets', [
            'policy' => [
                'no_show_policy' => (string) app(Settings::class)
                    ->get('ticket.no_show_policy', 'restore'),
                'expiration_hold_policy' => (string) app(Settings::class)
                    ->get('ticket.expiration_hold_policy', 'preserve_hold'),
            ],
            'options' => [
                'no_show' => [
                    [
                        'value' => 'restore',
                        'label' => __('messages.ticket_policy.restore_label'),
                        'description' => __('messages.ticket_policy.restore_description'),
                    ],
                    [
                        'value' => 'consume',
                        'label' => __('messages.ticket_policy.consume_label'),
                        'description' => __('messages.ticket_policy.consume_description'),
                    ],
                ],
                'expiration_hold' => [
                    [
                        'value' => 'preserve_hold',
                        'label' => __('messages.ticket_policy.preserve_hold_label'),
                        'description' => __('messages.ticket_policy.preserve_hold_description'),
                    ],
                ],
            ],
        ]);
    }

    public function update(
        UpdateTicketPolicyRequest $request,
        UpdateTicketPolicy $action,
    ): RedirectResponse {
        $action->execute(
            $request->string('no_show_policy')->toString(),
            $request->string('expiration_hold_policy')->toString(),
            $request->string('reason')->toString(),
            $request->user(),
        );

        return back()->with('success', __('messages.ticket.policy_updated'));
    }
}
