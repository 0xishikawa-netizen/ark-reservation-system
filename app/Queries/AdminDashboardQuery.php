<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\Membership\MembershipStatus;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Ticket\TicketWalletStatus;
use App\Support\Jobs\FailedJobsReader;
use Illuminate\Support\Facades\DB;

final class AdminDashboardQuery
{
    /** 回数券の期限間近を判定する日数。 */
    private const TICKET_WARNING_DAYS = 14;

    public function __construct(
        private readonly FailedJobsReader $failedJobs,
    ) {}

    /**
     * @return array{
     *     today_reservation_count: int|null,
     *     next_arrivals: list<array{id: int, starts_at: string, customer_name: string, service_name: string, staff_name: string|null}>|null,
     *     needs_attention_payment_count: int|null,
     *     stale_pending_reservation_count: int|null,
     *     membership_attention: array{grace: int, paused: int, canceling: int}|null,
     *     ticket_warning_count: int|null,
     *     failed_jobs_count: int|null
     * }
     */
    public function get(
        bool $canViewReservations,
        bool $canManageMemberships,
        bool $canViewCustomers,
        bool $canViewFailedJobs,
    ): array {
        return [
            'today_reservation_count' => $canViewReservations
                ? $this->todayReservationCount()
                : null,
            'next_arrivals' => $canViewReservations
                ? $this->nextArrivals()
                : null,
            'needs_attention_payment_count' => $canViewReservations
                ? (int) DB::table('payments')->where('needs_attention', true)->count()
                : null,
            'stale_pending_reservation_count' => $canViewReservations
                ? $this->stalePendingReservationCount()
                : null,
            'membership_attention' => $canManageMemberships
                ? $this->membershipAttention()
                : null,
            'ticket_warning_count' => $canViewCustomers
                ? $this->ticketWarningCount()
                : null,
            'failed_jobs_count' => $canViewFailedJobs
                ? $this->failedJobs->count()
                : null,
        ];
    }

    private function todayReservationCount(): int
    {
        return (int) DB::table('reservations')
            ->whereDate('starts_at', today())
            ->whereNotIn('status', [
                ReservationStatus::Canceled->value,
                ReservationStatus::Expired->value,
            ])
            ->count();
    }

    /**
     * @return list<array{id: int, starts_at: string, customer_name: string, service_name: string, staff_name: string|null}>
     */
    private function nextArrivals(): array
    {
        return DB::table('reservations')
            ->join('customers', 'customers.user_id', '=', 'reservations.customer_id')
            ->join('users as customer_users', 'customer_users.id', '=', 'customers.user_id')
            ->join('services', 'services.id', '=', 'reservations.service_id')
            ->leftJoin('staff', 'staff.user_id', '=', 'reservations.staff_id')
            ->whereIn('reservations.status', [
                ReservationStatus::Confirmed->value,
                ReservationStatus::PendingExternalSync->value,
            ])
            ->where('reservations.starts_at', '>=', now())
            ->orderBy('reservations.starts_at')
            ->orderBy('reservations.id')
            ->limit(5)
            ->get([
                'reservations.id',
                'reservations.starts_at',
                'customer_users.name as customer_name',
                'services.name as service_name',
                'staff.display_name as staff_name',
            ])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'starts_at' => (string) $row->starts_at,
                'customer_name' => (string) $row->customer_name,
                'service_name' => (string) $row->service_name,
                'staff_name' => $row->staff_name === null ? null : (string) $row->staff_name,
            ])
            ->values()
            ->all();
    }

    private function stalePendingReservationCount(): int
    {
        return (int) DB::table('reservations')
            ->where('status', ReservationStatus::PendingPayment->value)
            ->where('payment_expires_at', '<', now())
            ->count();
    }

    /** @return array{grace: int, paused: int, canceling: int} */
    private function membershipAttention(): array
    {
        $counts = DB::table('memberships')
            ->whereIn('status', [
                MembershipStatus::Grace->value,
                MembershipStatus::Paused->value,
                MembershipStatus::Canceling->value,
            ])
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'grace' => (int) $counts->get(MembershipStatus::Grace->value, 0),
            'paused' => (int) $counts->get(MembershipStatus::Paused->value, 0),
            'canceling' => (int) $counts->get(MembershipStatus::Canceling->value, 0),
        ];
    }

    private function ticketWarningCount(): int
    {
        return (int) DB::table('ticket_wallets')
            ->where('status', TicketWalletStatus::Active->value)
            ->where(function ($query): void {
                $query
                    ->where('balance', '<=', 0)
                    ->orWhere('expires_at', '<=', today()->addDays(self::TICKET_WARNING_DAYS));
            })
            ->count();
    }
}
