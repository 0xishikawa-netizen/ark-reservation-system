<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ReservationStatus;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 予約台帳の「顧客・予約詳細パネル」用の集約クエリ（Peak Manager 参考の業務導線）。
 *
 * 予約カードを 1 回クリックしたときに必要な情報を 1 レスポンスで返す。
 * 既存の Query（回数券・月額プラン・来店履歴）を再利用し、N+1 を出さない。
 * Customer 360 の置き換えではなく「台帳でその場で確認する簡易版」。
 */
final class ReservationPanelQuery
{
    public function __construct(
        private readonly CustomerReservationListQuery $reservationList,
        private readonly CustomerTicketQuery $ticketQuery,
        private readonly AdminMembershipQuery $membershipQuery,
    ) {}

    private const HISTORY_INITIAL = 20;

    /**
     * @return array<string, mixed>
     */
    public function get(Reservation $reservation, bool $canManage, bool $canViewCustomer): array
    {
        $reservation->loadMissing([
            'service:id,name,price',
            'staff:user_id,display_name',
            'booth:id,name',
            'payments:id,reservation_id,amount,status,kind,created_at',
        ]);

        $customerId = (int) $reservation->customer_id;
        $lists = $canViewCustomer
            ? $this->upcomingAndHistory($customerId)
            : ['upcoming' => [], 'history' => ['items' => [], 'total' => 0, 'has_more' => false]];

        return [
            'can' => [
                'manage' => $canManage,
                'view_customer' => $canViewCustomer,
            ],
            'reservation' => $this->reservationPayload($reservation, $canManage),
            'today_reservation_id' => null,
            'customer' => $canViewCustomer ? $this->customerPayload($customerId) : null,
            'tickets' => $canViewCustomer ? $this->ticketsPayload($customerId) : [],
            'membership' => $canViewCustomer ? $this->membershipPayload($customerId) : null,
            'upcoming' => $lists['upcoming'],
            'history' => $lists['history'],
        ];
    }

    /**
     * 顧客検索から選ばれた顧客（特定の予約に紐づかない）を表示するための版（§15-16）。
     * 台帳が表示中の日付にその顧客の予約があれば `today_reservation_id` で知らせ、
     * 台帳側でカードを分かるようにする。
     *
     * @return array<string, mixed>
     */
    public function getForCustomer(
        int $customerId,
        bool $canManage,
        bool $canViewCustomer,
        ?string $referenceDate = null,
    ): array {
        $lists = $canViewCustomer
            ? $this->upcomingAndHistory($customerId)
            : ['upcoming' => [], 'history' => ['items' => [], 'total' => 0, 'has_more' => false]];

        $todayReservationId = $referenceDate === null ? null : DB::table('reservations')
            ->where('customer_id', $customerId)
            ->whereDate('starts_at', $referenceDate)
            ->whereIn('status', [
                ReservationStatus::PendingPayment->value,
                ReservationStatus::PendingExternalSync->value,
                ReservationStatus::Confirmed->value,
                ReservationStatus::Completed->value,
                ReservationStatus::NoShow->value,
            ])
            ->orderBy('starts_at')
            ->value('id');

        return [
            'can' => [
                'manage' => $canManage,
                'view_customer' => $canViewCustomer,
            ],
            'reservation' => null,
            'today_reservation_id' => $todayReservationId === null ? null : (int) $todayReservationId,
            'customer' => $canViewCustomer ? $this->customerPayload($customerId) : null,
            'tickets' => $canViewCustomer ? $this->ticketsPayload($customerId) : [],
            'membership' => $canViewCustomer ? $this->membershipPayload($customerId) : null,
            'upcoming' => $lists['upcoming'],
            'history' => $lists['history'],
        ];
    }

    /** @return array<string, mixed> */
    private function reservationPayload(Reservation $reservation, bool $canManage): array
    {
        $status = $reservation->status;
        /** @var Payment|null $payment */
        $payment = $reservation->payments->sortByDesc('id')->first();

        $amount = $reservation->final_amount !== null
            ? (int) $reservation->final_amount
            : (int) $reservation->service->price;

        return [
            'id' => (int) $reservation->id,
            'customer_id' => (int) $reservation->customer_id,
            'date' => $reservation->starts_at->toDateString(),
            'starts_at' => $reservation->starts_at->format('Y-m-d H:i:s'),
            'ends_at' => $reservation->ends_at->format('Y-m-d H:i:s'),
            'buffer_min' => (int) $reservation->buffer_min,
            'service_id' => (int) $reservation->service_id,
            'service_name' => (string) $reservation->service->name,
            'staff_id' => $reservation->staff_id,
            'staff_name' => $reservation->staff?->display_name,
            'is_staff_requested' => (bool) $reservation->is_staff_requested,
            'booth_name' => $reservation->booth?->name,
            'status' => $status->value,
            'status_label' => self::statusLabel($status),
            'source' => $reservation->source->value,
            'source_label' => self::sourceLabel($reservation->source),
            'payment_method' => $reservation->payment_method->value,
            'payment_method_label' => self::paymentMethodLabel($reservation->payment_method),
            'amount' => $amount,
            'notes' => $reservation->notes,
            'cancel_reason' => $reservation->cancel_reason,
            'version' => (int) $reservation->version,
            'edit_url' => "/admin/reservations/{$reservation->id}/edit",
            'payment' => $payment === null ? null : [
                'id' => (int) $payment->id,
                'amount' => (int) $payment->amount,
                'status' => $payment->status->value,
                'status_label' => ReservationPaymentSummaryQuery::statusLabel($payment->status),
                'url' => "/admin/payments/{$payment->id}",
            ],
            // 状態遷移は既存 ReservationStateMachine が正本。ここは表示用の目安のみ。
            'can_complete' => $canManage && $status === ReservationStatus::Confirmed,
            // 来店・会計入力（Task 11-19）。POSTで来店下書きを開き入力画面へ遷移する。
            'visit_entry_url' => (auth()->user()?->can('checkouts.manage') ?? false)
                && in_array($status, [ReservationStatus::Confirmed, ReservationStatus::Completed], true)
                ? "/admin/reservations/{$reservation->id}/visit" : null,
            'can_cancel' => $canManage && in_array($status, [
                ReservationStatus::PendingPayment,
                ReservationStatus::PendingExternalSync,
                ReservationStatus::Confirmed,
            ], true),
            'can_no_show' => $canManage && in_array($status, [
                ReservationStatus::PendingExternalSync,
                ReservationStatus::Confirmed,
            ], true),
            // 延長（Task 11-29）。担当スタッフが実施でき、必要資格も持つ施術だけを候補にする。
            'can_extend' => $canManage && $status === ReservationStatus::Confirmed,
            'extension_services' => $canManage && $status === ReservationStatus::Confirmed
                ? $this->extensionServices($reservation) : [],
        ];
    }

    /** @return list<array{id: int, name: string}> */
    private function extensionServices(Reservation $reservation): array
    {
        $query = Service::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id');
        if ($reservation->staff_id !== null) {
            $staffId = (int) $reservation->staff_id;
            $query->whereExists(fn ($assigned) => $assigned->selectRaw('1')->from('service_staff')
                ->whereColumn('service_staff.service_id', 'services.id')->where('service_staff.staff_id', $staffId))
                ->whereNotExists(fn ($missing) => $missing->selectRaw('1')->from('qualification_service as qs')
                    ->whereColumn('qs.service_id', 'services.id')
                    ->whereNotExists(fn ($held) => $held->selectRaw('1')->from('qualification_staff as qst')
                        ->whereColumn('qst.qualification_id', 'qs.qualification_id')->where('qst.staff_id', $staffId)));
        }

        return $query->get(['id', 'name'])->map(static fn (Service $service): array => ['id' => (int) $service->id, 'name' => (string) $service->name])->all();
    }

    /**
     * 台帳サイドパネル用の顧客情報。日常業務に必要な項目だけに絞る（§8）。
     * メール・生年月日・住所は出さない（Customer 360 側で確認する）。電話番号は
     * 台帳での本人確認によく使うため canViewCustomer と同じ権限で表示する。
     *
     * @return array<string, mixed>|null
     */
    private function customerPayload(int $customerId): ?array
    {
        $row = DB::table('customers')
            ->join('users', 'users.id', '=', 'customers.user_id')
            ->where('customers.user_id', $customerId)
            ->first(['customers.user_id', 'users.name', 'customers.kana', 'customers.gender', 'customers.member_no']);

        if ($row === null) {
            return null;
        }

        $customer = Customer::query()->find($customerId);
        $note = $customer?->note;
        $phone = $customer?->phone;

        $stats = DB::table('reservations')
            ->where('customer_id', $customerId)
            ->selectRaw(
                'COUNT(CASE WHEN status = ? THEN 1 END) AS visit_count, '
                .'MIN(CASE WHEN status = ? THEN starts_at END) AS first_visit, '
                .'MAX(CASE WHEN status = ? THEN starts_at END) AS last_visit',
                array_fill(0, 3, ReservationStatus::Completed->value),
            )
            ->first();

        return [
            'user_id' => $customerId,
            'member_no' => (string) $row->member_no,
            'name' => (string) $row->name,
            'kana' => $row->kana === null ? null : (string) $row->kana,
            'gender' => $row->gender === null ? null : (string) $row->gender,
            'phone' => $phone === null ? null : (string) $phone,
            'note' => $note,
            'visit_count' => (int) ($stats->visit_count ?? 0),
            'first_visit_at' => $stats?->first_visit === null
                ? null
                : CarbonImmutable::parse((string) $stats->first_visit)->toDateString(),
            'last_visit_at' => $stats?->last_visit === null
                ? null
                : CarbonImmutable::parse((string) $stats->last_visit)->toDateString(),
            'detail_url' => "/admin/customers/{$customerId}",
        ];
    }

    /** @return list<array<string, mixed>> */
    private function ticketsPayload(int $customerId): array
    {
        return collect($this->ticketQuery->walletsFor($customerId))
            ->filter(static fn (array $wallet): bool => $wallet['status'] === 'active')
            ->map(static fn (array $wallet): array => [
                'product_name' => $wallet['product_name'],
                'available' => $wallet['available'],
                'held' => $wallet['held'],
                'total' => $wallet['total'],
                'expires_at' => $wallet['expires_at'],
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function membershipPayload(int $customerId): ?array
    {
        $membership = $this->membershipQuery->forCustomer($customerId);

        if ($membership === null) {
            return null;
        }

        /** @var array<string, mixed> $plan */
        $plan = $membership['plan'];

        return [
            'plan_name' => (string) $plan['name'],
            'status' => (string) $membership['status'],
            'status_label' => (string) $membership['status_label'],
            'available' => (int) ($membership['available'] ?? 0),
            'usage_count_per_period' => (int) $plan['usage_count_per_period'],
            'current_period_end' => $membership['current_period_end'] ?? null,
        ];
    }

    /**
     * @return array{upcoming: list<array<string, mixed>>, history: array{items: list<array<string, mixed>>, total: int, has_more: bool}}
     */
    private function upcomingAndHistory(int $customerId): array
    {
        /** @var array{upcoming: list<array<string, mixed>>, past: list<array<string, mixed>>} $lists */
        $lists = $this->reservationList->get($customerId);

        $decorate = static fn (array $row): array => [
            ...$row,
            'date' => substr((string) $row['starts_at'], 0, 10),
            'status_label' => self::statusLabel(ReservationStatus::from((string) $row['status'])),
        ];

        $past = array_map($decorate, $lists['past']);

        return [
            'upcoming' => array_map($decorate, $lists['upcoming']),
            'history' => [
                'items' => array_slice($past, 0, self::HISTORY_INITIAL),
                'total' => count($past),
                'has_more' => count($past) > self::HISTORY_INITIAL,
            ],
        ];
    }

    public static function statusLabel(ReservationStatus $status): string
    {
        return match ($status) {
            ReservationStatus::PendingPayment => '支払い待ち',
            ReservationStatus::PendingExternalSync => '外部連携待ち',
            ReservationStatus::Confirmed => '予約確定',
            ReservationStatus::Completed => '来店完了',
            ReservationStatus::NoShow => '無断キャンセル',
            ReservationStatus::Canceled => 'キャンセル',
            ReservationStatus::Expired => '期限切れ',
        };
    }

    public static function sourceLabel(ReservationSource $source): string
    {
        return match ($source) {
            ReservationSource::Hotpepper => 'Hot Pepper',
            ReservationSource::Epark => 'EPARK',
            ReservationSource::ArkWeb => 'ARK Web',
            ReservationSource::PeakManager => 'Peak Manager',
            ReservationSource::SalonBoard => 'SALON BOARD',
            ReservationSource::External => '外部予約',
            ReservationSource::Admin => '管理画面',
        };
    }

    public static function paymentMethodLabel(PaymentMethod $method): string
    {
        return match ($method) {
            PaymentMethod::Single => 'カード決済',
            PaymentMethod::Membership => '月額プラン',
            PaymentMethod::Ticket => '回数券',
            PaymentMethod::Onsite => '店頭支払い',
            PaymentMethod::Unpaid => '未設定',
        };
    }
}
