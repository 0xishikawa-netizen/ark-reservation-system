<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Reporting\DailyReportService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DailyReportController extends Controller
{
    public function __invoke(Request $request, DailyReportService $reports): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        return response()->json([
            'data' => $reports->forDate((string) $validated['date']),
        ]);
    }
}
