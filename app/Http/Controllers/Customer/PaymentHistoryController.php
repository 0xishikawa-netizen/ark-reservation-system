<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Queries\CustomerPaymentHistoryQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaymentHistoryController extends Controller
{
    public function index(Request $request, CustomerPaymentHistoryQuery $query): Response
    {
        $customer = $request->user()?->customer;

        abort_unless($customer instanceof Customer, 403);

        return Inertia::render('Customer/Payments/Index', [
            'payments' => $query->for((int) $customer->user_id),
        ]);
    }
}
