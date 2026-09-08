<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Customer;
use App\Models\Reservation;
use App\Policies\CustomerPolicy;
use App\Policies\ReservationPolicy;
use App\Policies\SystemPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Reservation::class, ReservationPolicy::class);
        Gate::define('system.viewFailedJobs', [SystemPolicy::class, 'viewFailedJobs']);
        Gate::define('system.viewAuditLogs', [SystemPolicy::class, 'viewAuditLogs']);
    }
}
