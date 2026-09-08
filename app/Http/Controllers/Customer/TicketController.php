<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Queries\CustomerTicketQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TicketController extends Controller
{
    public function index(Request $request, CustomerTicketQuery $query): Response
    {
        $customer = $request->user()?->customer;

        if ($customer === null) {
            abort(403);
        }

        return Inertia::render('Customer/Tickets/Index', [
            'wallets' => $query->walletsFor((int) $customer->user_id),
            'history' => $query->historyFor((int) $customer->user_id),
        ]);
    }
}
