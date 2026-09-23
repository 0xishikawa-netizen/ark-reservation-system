<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Ticket\TicketLedgerService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdjustTicketRequest;
use App\Http\Requests\Admin\GrantTicketRequest;
use App\Http\Requests\Admin\RevokeTicketRequest;
use App\Models\Customer;
use App\Models\TicketProduct;
use App\Models\TicketWallet;
use App\Queries\CustomerProfileQuery;
use App\Queries\CustomerTicketQuery;
use App\Queries\TicketProductListQuery;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CustomerTicketController extends Controller
{
    public function show(
        Customer $customer,
        CustomerProfileQuery $profileQuery,
        CustomerTicketQuery $ticketQuery,
        TicketProductListQuery $productQuery,
    ): Response {
        $customer = $profileQuery->get($customer);

        return Inertia::render('Admin/Customers/Tickets', [
            'customer' => [
                'user_id' => (int) $customer->user_id,
                'name' => $customer->user->name,
            ],
            'wallets' => $ticketQuery->walletsFor((int) $customer->user_id),
            'history' => $ticketQuery->historyFor((int) $customer->user_id),
            'ticketProducts' => $productQuery->get()
                ->where('is_active', true)
                ->map(fn (TicketProduct $product): array => [
                    'id' => (int) $product->id,
                    'name' => $product->name,
                    'total_count' => (int) $product->total_count,
                ])
                ->values(),
            'can' => [
                'grant' => request()->user()?->can('ticket.grant') ?? false,
            ],
        ]);
    }

    public function grant(
        GrantTicketRequest $request,
        Customer $customer,
        TicketLedgerService $ledger,
    ): RedirectResponse {
        $product = TicketProduct::query()->findOrFail($request->integer('ticket_product_id'));

        $ledger->grant(
            $customer,
            $product,
            $request->has('count') ? $request->integer('count') : null,
            $request->string('operation_key')->toString(),
            $request->string('reason')->toString(),
            $request->user(),
        );

        return back()->with('success', __('messages.ticket.granted'));
    }

    public function revoke(
        RevokeTicketRequest $request,
        TicketWallet $ticketWallet,
        TicketLedgerService $ledger,
    ): RedirectResponse {
        $ledger->revoke(
            $ticketWallet,
            $request->integer('count'),
            $request->string('operation_key')->toString(),
            $request->string('reason')->toString(),
            $request->user(),
        );

        return back()->with('success', __('messages.ticket.revoked'));
    }

    public function adjust(
        AdjustTicketRequest $request,
        TicketWallet $ticketWallet,
        TicketLedgerService $ledger,
    ): RedirectResponse {
        $ledger->adjust(
            $ticketWallet,
            $request->integer('delta'),
            $request->string('operation_key')->toString(),
            $request->string('reason')->toString(),
            $request->user(),
        );

        return back()->with('success', __('messages.ticket.adjusted'));
    }
}
