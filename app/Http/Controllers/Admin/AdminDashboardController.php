<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Jobs\FailedJobsReader;
use Inertia\Inertia;
use Inertia\Response;

class AdminDashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Admin/Dashboard', [
            'failedJobsCount' => app(FailedJobsReader::class)->count(),
        ]);
    }
}
