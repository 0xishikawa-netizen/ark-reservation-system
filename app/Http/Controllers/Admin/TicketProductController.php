<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Ticket\CreateTicketProduct;
use App\Actions\Ticket\ToggleTicketProductActive;
use App\Actions\Ticket\UpdateTicketProduct;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTicketProductRequest;
use App\Http\Requests\Admin\UpdateTicketProductRequest;
use App\Models\TicketProduct;
use App\Queries\TicketProductListQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TicketProductController extends Controller
{
    public function index(TicketProductListQuery $query): Response
    {
        return Inertia::render('Admin/TicketProducts/Index', [
            'ticketProducts' => $query->get()->values(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/TicketProducts/Create');
    }

    public function store(
        StoreTicketProductRequest $request,
        CreateTicketProduct $createTicketProduct,
    ): RedirectResponse {
        $createTicketProduct->execute($request->validated(), $request->user());

        return back()->with('success', __('messages.ticket.product_created'));
    }

    public function edit(TicketProduct $ticketProduct): Response
    {
        return Inertia::render('Admin/TicketProducts/Edit', [
            'ticketProduct' => $ticketProduct->only([
                'id',
                'name',
                'total_count',
                'price',
                'validity_days',
                'is_active',
                'sort_order',
            ]),
        ]);
    }

    public function update(
        UpdateTicketProductRequest $request,
        TicketProduct $ticketProduct,
        UpdateTicketProduct $updateTicketProduct,
    ): RedirectResponse {
        $updateTicketProduct->execute($ticketProduct, $request->validated(), $request->user());

        return back()->with('success', __('messages.ticket.product_updated'));
    }

    public function setActive(
        Request $request,
        TicketProduct $ticketProduct,
        ToggleTicketProductActive $toggleTicketProductActive,
    ): RedirectResponse {
        $validated = $request->validate([
            'active' => ['required', 'boolean'],
        ]);

        $active = (bool) $validated['active'];
        $toggleTicketProductActive->execute($ticketProduct, $active, $request->user());

        return back()->with(
            'success',
            $active ? '回数券商品を有効化しました。' : '回数券商品を無効化しました。',
        );
    }
}
