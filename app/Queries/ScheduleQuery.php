<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\Reservation\ReservationStatus;
use App\Enums\Schedule\ScheduleBlockType;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ScheduleQuery
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @return array{
     *   staff: list<array{user_id: int, display_name: string, color: string, sort_order: int}>,
     *   shifts: list<array{staff_id: int, work_date: string, start_at: string, end_at: string}>,
     *   reservations: list<array{id: int, customer_id: int, customer_name: string, customer_gender: string|null, is_new_customer: bool, service_id: int, service_name: string, service_color: string, staff_id: int|null, booth_id: int|null, starts_at: string, ends_at: string, status: string, source: string, version: int, is_staff_requested: bool}>,
     *   blocks: list<array{id: int, staff_id: int|null, booth_id: int|null, date: string, start_at: string, end_at: string, type: string, type_label: string, title: string|null, note: string|null}>,
     *   business_hours: array{open: string, close: string, slot_minutes: int},
     *   view: 'day'|'week',
     *   axis: 'staff'|'booth'|'both',
     *   range: array{start: string, end: string},
     *   days: list<string>,
     *   booths: list<array{id: int, name: string, sort_order: int}>,
     *   summary: array{total: int, completed: int, new_customers: int, repeat_customers: int, canceled: int, no_show: int, revenue: int}|null
     * }
     */
    public function get(
        CarbonImmutable $date,
        ?int $staffId = null,
        string $view = 'day',
        string $axis = 'staff',
    ): array {
        $rangeStart = $view === 'week' ? $date->startOfWeek() : $date;
        $rangeEnd = $view === 'week' ? $date->endOfWeek() : $date;
        $days = [];

        for ($day = $rangeStart; $day->lte($rangeEnd); $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        $staff = DB::table('staff')
            ->where('is_bookable', true)
            ->when($staffId !== null, fn ($query) => $query->where('user_id', $staffId))
            ->orderBy('sort_order')
            ->orderBy('user_id')
            ->get(['user_id', 'display_name', 'color', 'sort_order'])
            ->map(static fn (object $row): array => [
                'user_id' => (int) $row->user_id,
                'display_name' => (string) $row->display_name,
                'color' => (string) $row->color,
                'sort_order' => (int) $row->sort_order,
            ])
            ->all();
        $staffIds = array_column($staff, 'user_id');

        // 週表示でも「勤務外」を判定できるよう、日表示と同じ条件（staff/both軸）のまま
        // 対象期間（日表示なら1日・週表示なら7日）ぶんをまとめて1クエリで取得する（N+1禁止）。
        $shifts = ! in_array($axis, ['staff', 'both'], true) || $staffIds === []
            ? []
            : DB::table('staff_shifts')
                ->whereBetween('work_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
                ->whereIn('staff_id', $staffIds)
                ->orderBy('staff_id')
                ->orderBy('work_date')
                ->orderBy('start_at')
                ->get(['staff_id', 'work_date', 'start_at', 'end_at'])
                ->map(static fn (object $row): array => [
                    'staff_id' => (int) $row->staff_id,
                    'work_date' => (string) $row->work_date,
                    'start_at' => (string) $row->start_at,
                    'end_at' => (string) $row->end_at,
                ])
                ->all();

        $reservations = DB::table('reservations')
            ->join('customers', 'customers.user_id', '=', 'reservations.customer_id')
            ->join('users as customer_users', 'customer_users.id', '=', 'customers.user_id')
            ->join('services', 'services.id', '=', 'reservations.service_id')
            // starts_at インデックスを活かすため DATE() ではなく境界値で範囲指定する。
            ->where('reservations.starts_at', '>=', $rangeStart->startOfDay())
            ->where('reservations.starts_at', '<', $rangeEnd->addDay()->startOfDay())
            ->whereIn('reservations.status', [
                ReservationStatus::PendingPayment->value,
                ReservationStatus::PendingExternalSync->value,
                ReservationStatus::Confirmed->value,
                ReservationStatus::Completed->value,
                ReservationStatus::NoShow->value,
            ])
            ->when($staffId !== null, fn ($query) => $query->where('reservations.staff_id', $staffId))
            ->orderBy('reservations.starts_at')
            ->get([
                'reservations.id',
                'reservations.customer_id',
                'reservations.staff_id',
                'reservations.booth_id',
                'reservations.starts_at',
                'reservations.ends_at',
                'reservations.status',
                'reservations.source',
                'reservations.version',
                'reservations.is_staff_requested',
                'customer_users.name as customer_name',
                'customers.gender as customer_gender',
                'services.id as service_id',
                'services.name as service_name',
                'services.color as service_color',
            ]);

        // 「新規」判定：来店実績としてカウントするステータスの中で、その顧客の
        // 最も早い予約が当該予約であれば新規客とみなす（キャンセル・期限切れは除外）。
        $customerIds = $reservations->pluck('customer_id')->unique()->all();
        $firstVisitAt = $customerIds === []
            ? collect()
            : DB::table('reservations')
                ->whereIn('customer_id', $customerIds)
                ->whereIn('status', [
                    ReservationStatus::PendingPayment->value,
                    ReservationStatus::PendingExternalSync->value,
                    ReservationStatus::Confirmed->value,
                    ReservationStatus::Completed->value,
                    ReservationStatus::NoShow->value,
                ])
                ->groupBy('customer_id')
                ->selectRaw('customer_id, MIN(starts_at) as first_starts_at')
                ->pluck('first_starts_at', 'customer_id');

        $reservations = $reservations
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'customer_id' => (int) $row->customer_id,
                'customer_name' => (string) $row->customer_name,
                'customer_gender' => $row->customer_gender === null ? null : (string) $row->customer_gender,
                'is_new_customer' => isset($firstVisitAt[$row->customer_id])
                    && (string) $firstVisitAt[$row->customer_id] === (string) $row->starts_at,
                'service_id' => (int) $row->service_id,
                'service_name' => (string) $row->service_name,
                'service_color' => (string) $row->service_color,
                'staff_id' => $row->staff_id === null ? null : (int) $row->staff_id,
                'booth_id' => $row->booth_id === null ? null : (int) $row->booth_id,
                'starts_at' => (string) $row->starts_at,
                'ends_at' => (string) $row->ends_at,
                'status' => (string) $row->status,
                'source' => (string) $row->source,
                'version' => (int) $row->version,
                'is_staff_requested' => (bool) $row->is_staff_requested,
            ])
            ->all();

        $blocks = DB::table('staff_schedule_blocks')
            ->where('work_date', '>=', $rangeStart->toDateString())
            ->where('work_date', '<=', $rangeEnd->toDateString())
            ->when($staffId !== null, fn ($query) => $query->where('staff_id', $staffId))
            ->orderBy('work_date')
            ->orderBy('start_at')
            ->get(['id', 'staff_id', 'booth_id', 'work_date', 'start_at', 'end_at', 'type', 'title', 'note'])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'staff_id' => $row->staff_id === null ? null : (int) $row->staff_id,
                'booth_id' => $row->booth_id === null ? null : (int) $row->booth_id,
                'date' => (string) $row->work_date,
                'start_at' => substr((string) $row->start_at, 0, 5),
                'end_at' => substr((string) $row->end_at, 0, 5),
                'type' => (string) $row->type,
                'type_label' => ScheduleBlockType::from((string) $row->type)->label(),
                'title' => $row->title === null ? null : (string) $row->title,
                'note' => $row->note === null ? null : (string) $row->note,
            ])
            ->all();

        $booths = in_array($axis, ['booth', 'both'], true)
            ? DB::table('booths')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'name', 'sort_order'])
                ->map(static fn (object $row): array => [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'sort_order' => (int) $row->sort_order,
                ])
                ->all()
            : [];

        return [
            'staff' => $staff,
            'shifts' => $shifts,
            'reservations' => $reservations,
            'summary' => $view === 'day' ? $this->dailySummary($date) : null,
            'business_hours' => [
                'open' => (string) $this->settings->get(
                    'business_hours.open',
                    config('reservation.business_hours.open', '10:00'),
                ),
                'close' => (string) $this->settings->get(
                    'business_hours.close',
                    config('reservation.business_hours.close', '22:00'),
                ),
                'slot_minutes' => (int) $this->settings->get(
                    'reservation.slot_minutes',
                    config('reservation.slot_minutes', 15),
                ),
            ],
            'view' => $view,
            'axis' => $axis,
            'range' => [
                'start' => $rangeStart->toDateString(),
                'end' => $rangeEnd->toDateString(),
            ],
            'days' => $days,
            'booths' => $booths,
            'blocks' => $blocks,
        ];
    }

    /**
     * 当日サマリー（§38-39）。既存 DB から正確に算出できるものだけを対象にする
     * （曖昧な指標は作らない）。スタッフ絞り込みに関わらず、その日の店舗全体を集計する。
     *
     * @return array{total: int, completed: int, new_customers: int, repeat_customers: int, canceled: int, no_show: int, revenue: int}
     */
    private function dailySummary(CarbonImmutable $date): array
    {
        $rows = DB::table('reservations')
            ->join('services', 'services.id', '=', 'reservations.service_id')
            ->where('reservations.starts_at', '>=', $date->startOfDay())
            ->where('reservations.starts_at', '<', $date->addDay()->startOfDay())
            ->whereIn('reservations.status', [
                ReservationStatus::PendingPayment->value,
                ReservationStatus::PendingExternalSync->value,
                ReservationStatus::Confirmed->value,
                ReservationStatus::Completed->value,
                ReservationStatus::NoShow->value,
                ReservationStatus::Canceled->value,
            ])
            ->get([
                'reservations.customer_id',
                'reservations.status',
                'reservations.final_amount',
                'services.price as service_price',
            ]);

        $customerIds = $rows->pluck('customer_id')->unique()->values()->all();
        $firstVisitAt = $customerIds === []
            ? collect()
            : DB::table('reservations')
                ->whereIn('customer_id', $customerIds)
                ->whereIn('status', [
                    ReservationStatus::PendingPayment->value,
                    ReservationStatus::PendingExternalSync->value,
                    ReservationStatus::Confirmed->value,
                    ReservationStatus::Completed->value,
                    ReservationStatus::NoShow->value,
                ])
                ->groupBy('customer_id')
                ->selectRaw('customer_id, MIN(starts_at) as first_starts_at')
                ->pluck('first_starts_at', 'customer_id');

        $dayStart = $date->startOfDay()->format('Y-m-d H:i:s');
        $dayEnd = $date->addDay()->startOfDay()->format('Y-m-d H:i:s');

        $newCustomerIds = [];
        $repeatCustomerIds = [];
        $completed = 0;
        $canceled = 0;
        $noShow = 0;
        $revenue = 0;

        foreach ($rows as $row) {
            $status = (string) $row->status;

            if ($status === ReservationStatus::Completed->value) {
                $completed++;
                $revenue += $row->final_amount !== null ? (int) $row->final_amount : (int) $row->service_price;
            } elseif ($status === ReservationStatus::Canceled->value) {
                $canceled++;
            } elseif ($status === ReservationStatus::NoShow->value) {
                $noShow++;
            }

            $first = $firstVisitAt[$row->customer_id] ?? null;
            $isNewToday = $first !== null && (string) $first >= $dayStart && (string) $first < $dayEnd;

            if ($isNewToday) {
                $newCustomerIds[$row->customer_id] = true;
            } else {
                $repeatCustomerIds[$row->customer_id] = true;
            }
        }

        return [
            'total' => $rows->count(),
            'completed' => $completed,
            'new_customers' => count($newCustomerIds),
            'repeat_customers' => count($repeatCustomerIds),
            'canceled' => $canceled,
            'no_show' => $noShow,
            'revenue' => $revenue,
        ];
    }
}
