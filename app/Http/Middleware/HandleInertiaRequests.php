<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames()->values()->all(),
                    'email_verified' => $user->hasVerifiedEmail(),
                    'two_factor_enabled' => (bool) $user->two_factor_enabled,
                ],
                'can' => [
                    'staffManage' => $user?->can('staff.manage') ?? false,
                    'servicesManage' => $user?->can('services.manage') ?? false,
                    'boothsManage' => $user?->can('booths.manage') ?? false,
                    'shiftsManage' => $user?->can('shifts.manage') ?? false,
                    'customersView' => $user?->can('customers.view') ?? false,
                    'customersManage' => $user?->can('customers.manage') ?? false,
                    'reservationsView' => $user?->can('reservations.view') ?? false,
                    'reservationsManage' => $user?->can('reservations.manage') ?? false,
                    'failedJobsView' => $user?->can('failed_jobs.view') ?? false,
                    'auditLogsView' => $user?->can('audit_logs.view') ?? false,
                    'ticketPolicyManage' => $user?->can('ticket_policy.manage') ?? false,
                    'ticketProductsManage' => $user?->can('ticket_products.manage') ?? false,
                    'ticketGrant' => $user?->can('ticket.grant') ?? false,
                    'membershipManage' => $user?->can('membership.manage') ?? false,
                    'integrationsView' => $user?->can('integrations.view') ?? false,
                    'integrationsManage' => $user?->can('integrations.manage') ?? false,
                    'settingsManage' => $user?->can('settings.manage') ?? false,
                    'rolesManage' => $user?->can('roles.manage') ?? false,
                    'reportsView' => $user?->can('reports.view') ?? false,
                    'reportsManage' => $user?->can('reports.manage') ?? false,
                    'salesView' => $user?->can('sales.view') ?? false,
                    'checkoutsManage' => $user?->can('checkouts.manage') ?? false,
                ],
                'reportRoutes' => [
                    'dailyNotes' => route('admin.reports.daily-notes'),
                    'monthly' => route('admin.reports.monthly'),
                    'customers' => route('admin.reports.customers'),
                    'staffUtilization' => route('admin.reports.staff-utilization'),
                    'timeBands' => route('admin.reports.time-bands'),
                    'staffSales' => route('admin.reports.staff-sales'),
                    'courseSales' => route('admin.reports.course-sales'),
                    'annual' => route('admin.reports.annual'),
                ],
            ],
            'flash' => [
                'success' => fn (): mixed => $request->session()->get('success'),
                'error' => fn (): mixed => $request->session()->get('error'),
                // 「結果不明・確認中」など、成功でも失敗でもない案内（3DS/SCA sync 等で使う）。
                'info' => fn (): mixed => $request->session()->get('info'),
            ],
        ];
    }
}
