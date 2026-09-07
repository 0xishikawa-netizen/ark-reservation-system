<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class SystemPolicy
{
    public function viewFailedJobs(User $user): bool
    {
        return $user->can('failed_jobs.view');
    }

    public function viewAuditLogs(User $user): bool
    {
        return $user->can('audit_logs.view');
    }
}
