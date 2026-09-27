<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Reporting\DailyLedgerService;
use App\Domain\Reporting\MonthlyOverviewService;
use App\Domain\Reporting\ReservationAnalysisService;
use App\Enums\Reporting\SalesBasis;
use App\Http\Controllers\Controller;
use App\Support\Business\BusinessTime;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 月次レポートの新しいタブ（概要・日計明細・予約分析。Task 11-31）。
 * 月の切替は同じURLへ year/month を付けて開き直す（タブごとに、そのタブのデータだけ取得する）。
 */
final class MonthlyReportHubController extends Controller
{
    public function overview(Request $request, MonthlyOverviewService $overview, BusinessTime $time): Response
    {
        [$year, $month, $input] = $this->month($request, $time, [
            'basis' => ['nullable', Rule::enum(SalesBasis::class)],
            'as_of_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return Inertia::render('Admin/Reports/Overview', [
            'report' => $overview->forMonth($year, $month, SalesBasis::tryFrom((string) ($input['basis'] ?? '')) ?? SalesBasis::PaymentDate, $input['as_of_date'] ?? null),
        ]);
    }

    public function dailyLedger(Request $request, DailyLedgerService $ledger, BusinessTime $time): Response
    {
        [$year, $month] = $this->month($request, $time);

        return Inertia::render('Admin/Reports/DailyLedger', ['report' => $ledger->forMonth($year, $month)]);
    }

    public function reservationAnalysis(Request $request, ReservationAnalysisService $analysis, BusinessTime $time): Response
    {
        [$year, $month] = $this->month($request, $time);

        return Inertia::render('Admin/Reports/ReservationAnalysis', ['report' => $analysis->forMonth($year, $month)]);
    }

    /**
     * @param  array<string, mixed>  $extraRules
     * @return array{0: int, 1: int, 2: array<string, mixed>}
     */
    private function month(Request $request, BusinessTime $time, array $extraRules = []): array
    {
        $input = $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            ...$extraRules,
        ]);
        $today = $time->businessDate();

        return [(int) ($input['year'] ?? $today->year), (int) ($input['month'] ?? $today->month), $input];
    }
}
