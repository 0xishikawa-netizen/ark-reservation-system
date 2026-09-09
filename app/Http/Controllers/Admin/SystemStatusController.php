<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\System\SystemStatusReport;
use Inertia\Inertia;
use Inertia\Response;

final class SystemStatusController extends Controller
{
    public function show(SystemStatusReport $report): Response
    {
        return Inertia::render('Admin/System/Status', $report->generate());
    }
}
