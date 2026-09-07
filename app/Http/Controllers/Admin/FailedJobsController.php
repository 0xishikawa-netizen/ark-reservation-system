<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Jobs\FailedJobsReader;
use Inertia\Inertia;
use Inertia\Response;

class FailedJobsController extends Controller
{
    public function __invoke(FailedJobsReader $reader): Response
    {
        return Inertia::render('Admin/System/FailedJobs', [
            'jobs' => $reader->latest(),
            'count' => $reader->count(),
        ]);
    }
}
