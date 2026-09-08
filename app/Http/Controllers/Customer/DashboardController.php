<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Queries\CustomerDashboardQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, CustomerDashboardQuery $query): Response
    {
        $customer = $request->user()?->customer;

        abort_unless($customer instanceof Customer, 403);

        return Inertia::render('Customer/Dashboard', $query->for((int) $customer->user_id));
    }
}
