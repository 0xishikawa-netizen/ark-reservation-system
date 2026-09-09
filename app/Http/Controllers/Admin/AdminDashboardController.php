<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Queries\AdminDashboardQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AdminDashboardController extends Controller
{
    public function __invoke(Request $request, AdminDashboardQuery $query): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $dashboard = $query->get(
            canViewReservations: $user->can('reservations.view'),
            canManageMemberships: $user->can('membership.manage'),
            canViewCustomers: $user->can('customers.view'),
            canViewFailedJobs: $user->can('failed_jobs.view'),
        );

        return Inertia::render('Admin/Dashboard', [
            ...$dashboard,
            'dashboardDate' => today()->toDateString(),
            'failedJobsCount' => $dashboard['failed_jobs_count'] ?? 0,
        ]);
    }
}
