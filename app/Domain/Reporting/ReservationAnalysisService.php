<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Enums\Reservation\ReservationStatus;
use App\Enums\Visit\VisitStatus;
use App\Support\Business\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 予約分析（Task 11-31。旧Excelにない ARK 独自の指標）。入力は増やさず、予約・来店の既存Factだけから算出する。
 * 対象は「予約日（starts_at。JSTの壁時計値）がその月」の予約。率は分子合計÷分母合計で、分母0は NULL。
 */
final class ReservationAnalysisService
{
    /** リードタイム（予約作成日→予約日）の区分。 */
    public const LEAD_BUCKETS = ['same_day' => [0, 0], 'd1_3' => [1, 3], 'd4_7' => [4, 7], 'd8_14' => [8, 14], 'd15_plus' => [15, null]];

    public function __construct(private readonly BusinessTime $businessTime) {}

    /** @return array<string, mixed> */
    public function forMonth(int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $this->businessTime->timezone());
        $from = $start->format('Y-m-d 00:00:00');
        $to = $start->addMonth()->format('Y-m-d 00:00:00');
        $rows = DB::table('reservations as r')->leftJoin('staff as s', 's.user_id', '=', 'r.staff_id')
            ->where('r.starts_at', '>=', $from)->where('r.starts_at', '<', $to)
            ->get(['r.id', 'r.status', 'r.source', 'r.inflow_channel', 'r.starts_at', 'r.created_at', 'r.staff_id', 's.display_name']);

        $countable = $rows->reject(fn (object $row): bool => in_array($row->status, [ReservationStatus::Expired->value, ReservationStatus::PendingPayment->value], true));
        $byStatus = $countable->countBy('status');
        $total = $countable->count();
        $canceled = (int) ($byStatus[ReservationStatus::Canceled->value] ?? 0);
        $noShow = (int) ($byStatus[ReservationStatus::NoShow->value] ?? 0);
        $completed = (int) ($byStatus[ReservationStatus::Completed->value] ?? 0);
        $rate = static fn (int $numerator, int $denominator): ?float => $denominator === 0 ? null : $numerator / $denominator;

        // 次回予約率：完了来店のうち、会計時点で次回予約があった割合（未判定は分母から除く）。
        $visits = DB::table('visits')->where('status', VisitStatus::Completed->value)
            ->whereBetween('business_date', [$start->toDateString(), $start->endOfMonth()->toDateString()])
            ->selectRaw('COALESCE(SUM(CASE WHEN future_reservation_exists_at_checkout = 1 THEN 1 ELSE 0 END), 0) AS yes')
            ->selectRaw('COALESCE(SUM(CASE WHEN future_reservation_exists_at_checkout IS NOT NULL THEN 1 ELSE 0 END), 0) AS known')
            ->first();

        $group = static function ($collection, callable $key): array {
            $out = [];
            foreach ($collection as $row) {
                $k = (string) $key($row);
                $out[$k] ??= ['key' => $k, 'total' => 0, 'completed' => 0, 'canceled' => 0, 'no_show' => 0];
                $out[$k]['total']++;
                $out[$k]['completed'] += $row->status === ReservationStatus::Completed->value ? 1 : 0;
                $out[$k]['canceled'] += $row->status === ReservationStatus::Canceled->value ? 1 : 0;
                $out[$k]['no_show'] += $row->status === ReservationStatus::NoShow->value ? 1 : 0;
            }

            return array_values($out);
        };
        $tz = $this->businessTime->timezone();
        $leadDays = static function (object $row) use ($tz): ?int {
            if ($row->created_at === null) {
                return null;
            }
            $created = CarbonImmutable::parse((string) $row->created_at, 'UTC')->setTimezone($tz)->startOfDay();
            $day = CarbonImmutable::parse(substr((string) $row->starts_at, 0, 10), $tz);

            return max(0, (int) $created->diffInDays($day, false));
        };
        $lead = array_fill_keys(array_keys(self::LEAD_BUCKETS), 0);
        $leadUnknown = 0;
        foreach ($countable as $row) {
            $days = $leadDays($row);
            if ($days === null) {
                $leadUnknown++;

                continue;
            }
            foreach (self::LEAD_BUCKETS as $bucket => [$min, $max]) {
                if ($days >= $min && ($max === null || $days <= $max)) {
                    $lead[$bucket]++;
                    break;
                }
            }
        }
        $weekday = $group($countable, fn (object $row): int => CarbonImmutable::parse(substr((string) $row->starts_at, 0, 10))->dayOfWeekIso);
        usort($weekday, fn (array $a, array $b): int => (int) $a['key'] <=> (int) $b['key']);
        $hours = $group($countable, fn (object $row): int => (int) substr((string) $row->starts_at, 11, 2));
        usort($hours, fn (array $a, array $b): int => (int) $a['key'] <=> (int) $b['key']);

        return [
            'year' => $year, 'month' => $month, 'month_key' => $start->format('Y-m'),
            'totals' => [
                'reservation_count' => $total, 'completed' => $completed, 'canceled' => $canceled, 'no_show' => $noShow,
                'upcoming' => (int) ($byStatus[ReservationStatus::Confirmed->value] ?? 0) + (int) ($byStatus[ReservationStatus::PendingExternalSync->value] ?? 0),
                'cancel_rate' => $rate($canceled, $total), 'no_show_rate' => $rate($noShow, $total),
                'next_reservation_rate' => $rate((int) $visits->yes, (int) $visits->known),
                'next_reservation_numerator' => (int) $visits->yes, 'next_reservation_denominator' => (int) $visits->known,
            ],
            'by_source' => $group($countable, fn (object $row): string => $row->source.($row->inflow_channel !== null && $row->source === 'ARK_WEB' ? ':'.$row->inflow_channel : '')),
            'by_weekday' => $weekday,
            'by_hour' => $hours,
            'by_staff' => $group($countable, fn (object $row): string => $row->display_name ?? ''),
            'lead_time' => ['buckets' => $lead, 'unknown' => $leadUnknown],
        ];
    }
}
