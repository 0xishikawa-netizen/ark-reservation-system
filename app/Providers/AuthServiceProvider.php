<?php

declare(strict_types=1);

namespace App\Providers;

use App\Policies\SystemPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('system.viewFailedJobs', [SystemPolicy::class, 'viewFailedJobs']);
        Gate::define('system.viewAuditLogs', [SystemPolicy::class, 'viewAuditLogs']);
    }
}
