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
                    'failedJobsView' => $user?->can('failed_jobs.view') ?? false,
                    'auditLogsView' => $user?->can('audit_logs.view') ?? false,
                ],
            ],
            'flash' => [
                'success' => fn (): mixed => $request->session()->get('success'),
                'error' => fn (): mixed => $request->session()->get('error'),
            ],
        ];
    }
}
