<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Customer\CreateProvisionalCustomer;
use App\Actions\Reservation\UpdateReservationNomination;
use App\Actions\Reservation\UpdateReservationNotes;
use App\Domain\Payment\ReservationAdjustmentService;
use App\Domain\Reservation\AvailabilityService;
use App\Domain\Reservation\RescheduleInput;
use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Visit\CheckoutExemptionReason;
use App\Exceptions\Reservation\StaleReservationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdjustReservationAmountRequest;
use App\Http\Requests\Admin\StoreAdminReservationRequest;
use App\Http\Requests\Admin\UpdateAdminReservationRequest;
use App\Models\Reservation;
use App\Models\User;
use App\Queries\CustomerLookupQuery;
use App\Queries\ReservationFormOptionsQuery;
use App\Queries\ReservationListQuery;
use App\Queries\ReservationPanelQuery;
use App\Queries\ReservationPaymentSummaryQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class ReservationController extends Controller
{
    public function index(
        Request $request,
        ReservationListQuery $query,
        ReservationFormOptionsQuery $optionsQuery,
        CustomerLookupQuery $customerLookup,
    ): Response {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'staff_id' => ['nullable', 'integer', 'exists:staff,user_id'],
            'status' => ['nullable', Rule::enum(ReservationStatus::class)],
            'customer_id' => ['nullable', 'integer', 'exists:customers,user_id'],
        ]);
        $date = $this->nullableString($validated['date'] ?? null);
        $status = $this->nullableString($validated['status'] ?? null);
        $staffId = isset($validated['staff_id']) ? (int) $validated['staff_id'] : null;
        $customerId = isset($validated['customer_id']) ? (int) $validated['customer_id'] : null;

        return Inertia::render('Admin/Reservations/Index', [
            'reservations' => $query->paginate($date, $staffId, $status, customerId: $customerId),
            'staff' => $optionsQuery->staff(),
            'filters' => [
                'date' => $date,
                'staff_id' => $staffId,
                'status' => $status,
                'customer_id' => $customerId,
            ],
            'filtered_customer' => $customerId === null ? null : $customerLookup->find($customerId),
        ]);
    }

    public function customerSearch(
        Request $request,
        CustomerLookupQuery $query,
    ): JsonResponse {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($query->search((string) ($validated['q'] ?? '')));
    }

    /**
     * 電話予約などで未登録のお客様の予約を取るための仮登録（§新規のお客様）。
     * 氏名・カナ・電話番号のうち、聞けたものだけで顧客を作れる。
     */
    public function storeProvisionalCustomer(
        Request $request,
        CreateProvisionalCustomer $createProvisionalCustomer,
        CustomerLookupQuery $query,
    ): JsonResponse {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'kana' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        // 全部空だと後から誰の予約か辿れないため、最低1つは必須にする。
        if (collect($validated)->filter(fn (?string $v): bool => trim((string) $v) !== '')->isEmpty()) {
            throw ValidationException::withMessages([
                'name' => __('messages.reservation.customer_search_required'),
            ]);
        }

        $customer = $createProvisionalCustomer->execute($validated, $this->userFor($request));

        return response()->json($query->find((int) $customer->user_id));
    }

    /**
     * 予約台帳の「顧客・予約詳細パネル」用の集約データ（§17）。
     * 予約カード 1 クリックで必要な情報を 1 レスポンスで返す。閲覧は can:reservations.view、
     * 顧客 PII は can:customers.view を持つ場合のみ含める（§19・サーバー側でも認可）。
     */
    public function panel(
        Request $request,
        Reservation $reservation,
        ReservationPanelQuery $query,
    ): JsonResponse {
        $user = $request->user();

        return response()->json($query->get(
            $reservation,
            canManage: $user?->can('reservations.manage') === true,
            canViewCustomer: $user?->can('customers.view') === true,
        ));
    }

    public function availability(
        Request $request,
        AvailabilityService $availabilityService,
    ): JsonResponse {
        $validated = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'staff_id' => ['nullable', 'integer', 'exists:staff,user_id'],
            'booth_id' => ['nullable', 'integer', 'exists:booths,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'buffer_min' => ['nullable', 'integer', 'min:0', 'max:60'],
            // 台帳の「メニューで空きを確認」用：空きブースの有無も考慮する。
            'with_booths' => ['nullable', 'boolean'],
        ]);

        return response()->json($availabilityService->openStartTimes(
            (int) $validated['service_id'],
            isset($validated['staff_id']) ? (int) $validated['staff_id'] : null,
            isset($validated['booth_id']) ? (int) $validated['booth_id'] : null,
            CarbonImmutable::parse((string) $validated['date']),
            (int) ($validated['buffer_min'] ?? 0),
            (bool) ($validated['with_booths'] ?? false),
        ));
    }

    /**
     * メニュー選択時にブースを自動提案する（空いていなければ null）。
     * あくまで初期提案。実際の予約作成時はサーバー側で改めて検証される。
     */
    public function availableBooth(
        Request $request,
        AvailabilityService $availabilityService,
    ): JsonResponse {
        $validated = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'starts_at' => ['required', 'date'],
        ]);

        return response()->json([
            'booth_id' => $availabilityService->firstAvailableBooth(
                (int) $validated['service_id'],
                CarbonImmutable::parse((string) $validated['starts_at']),
            ),
        ]);
    }

    public function store(
        StoreAdminReservationRequest $request,
        ReservationService $reservationService,
    ): RedirectResponse {
        $data = $request->validated();
        $startsAt = CarbonImmutable::parse((string) $data['starts_at']);
        $user = $this->userFor($request);

        $reservationService->create(new ReservationInput(
            customerId: (int) $data['customer_id'],
            serviceId: (int) $data['service_id'],
            staffId: isset($data['staff_id']) ? (int) $data['staff_id'] : null,
            boothId: isset($data['booth_id']) ? (int) $data['booth_id'] : null,
            startsAt: $startsAt,
            source: ReservationSource::Admin,
            actorUserId: (int) $user->id,
            notes: $this->normalizeNotes($data['notes'] ?? null),
            adminContext: true,
            isStaffRequested: (bool) ($data['is_staff_requested'] ?? false),
            bufferMin: (int) ($data['buffer_min'] ?? 0),
        ));

        return redirect()
            ->route('admin.schedule.index', $this->scheduleReturnParams($request, $startsAt->toDateString()))
            ->with('success', __('messages.reservation.created'));
    }

    /**
     * 台帳の軸・スタッフ絞り込み表示状態を保ったまま予約台帳へ戻る（date だけの
     * リダイレクトだと軸が「スタッフ」にリセットされてしまうのを防ぐ）。
     *
     * @return array<string, string>
     */
    private function scheduleReturnParams(Request $request, string $date): array
    {
        $params = ['date' => $date];

        foreach (['view', 'axis', 'staff_id'] as $key) {
            $value = $request->query($key);

            if ($value !== null && $value !== '') {
                $params[$key] = (string) $value;
            }
        }

        return $params;
    }

    public function edit(
        Reservation $reservation,
        ReservationFormOptionsQuery $query,
        ReservationPaymentSummaryQuery $paymentSummary,
    ): Response {
        return Inertia::render('Admin/Reservations/Edit', [
            'reservation' => $query->reservation((int) $reservation->id),
            'payment_summary' => $paymentSummary->get($reservation),
            ...$query->get(),
        ]);
    }

    public function adjustment(
        AdjustReservationAmountRequest $request,
        Reservation $reservation,
        ReservationAdjustmentService $adjustments,
    ): RedirectResponse {
        $finalAmount = (int) $request->validated('final_amount');

        if ($finalAmount < $adjustments->netReceived($reservation)) {
            abort_unless($request->user()?->can('refund.execute') === true, 403);
        }

        $result = $adjustments->requestAdjustment(
            $reservation,
            $finalAmount,
            $this->userFor($request),
        );

        if (($result['refund_failed'] ?? false) === true) {
            return back()->with(
                'error',
                '差額返金の一部または全部を完了できませんでした。要対応として確認してください。',
            );
        }

        return match ($result['outcome']) {
            'addon_created' => back()->with(
                'success',
                '差額のお支払いリンクを発行しました。お客様のマイページに表示されます。',
            ),
            'addon_reused' => back()->with(
                'info',
                '発行済みの差額支払いリンクをそのまま利用します。',
            ),
            'refunded' => back()->with(
                'success',
                '施術内容変更による差額を返金しました。',
            ),
            'no_change' => back()->with(
                'info',
                '実質受領額と最終施術金額が一致しているため、金銭処理はありません。',
            ),
            default => back()->with(
                'error',
                '差額のお支払いリンクを発行できませんでした。要対応として確認してください。',
            ),
        };
    }

    public function update(
        UpdateAdminReservationRequest $request,
        Reservation $reservation,
        ReservationService $reservationService,
        UpdateReservationNotes $updateNotes,
        UpdateReservationNomination $updateNomination,
    ): RedirectResponse {
        $data = $request->validated();
        $startsAt = CarbonImmutable::parse((string) $data['starts_at']);
        $staffId = isset($data['staff_id']) ? (int) $data['staff_id'] : null;
        $boothId = isset($data['booth_id']) ? (int) $data['booth_id'] : null;
        $version = (int) $data['version'];
        $notesProvided = array_key_exists('notes', $data);
        $notes = $notesProvided
            ? $this->normalizeNotes($data['notes'])
            : $reservation->notes;
        $notesChanged = $notesProvided && $notes !== $reservation->notes;
        $nominationProvided = array_key_exists('is_staff_requested', $data);
        $isStaffRequested = $nominationProvided
            ? (bool) $data['is_staff_requested']
            : (bool) $reservation->is_staff_requested;
        $nominationChanged = $nominationProvided && $isStaffRequested !== (bool) $reservation->is_staff_requested;
        $scheduleChanged = ! $startsAt->equalTo(CarbonImmutable::instance($reservation->starts_at))
            || $staffId !== ($reservation->staff_id === null ? null : (int) $reservation->staff_id)
            || $boothId !== ($reservation->booth_id === null ? null : (int) $reservation->booth_id);
        $user = $this->userFor($request);

        if ($scheduleChanged) {
            $reservation = $reservationService->reschedule(new RescheduleInput(
                reservationId: (int) $reservation->id,
                staffId: $staffId,
                boothId: $boothId,
                startsAt: $startsAt,
                expectedVersion: $version,
                actorUserId: (int) $user->id,
                adminContext: true,
                updateNotes: $notesProvided,
                notes: $notes,
                isStaffRequested: $nominationProvided ? $isStaffRequested : null,
            ));
        } else {
            if ($notesChanged) {
                $reservation = $updateNotes->execute($reservation, $notes, $version, $user);
                $version = $reservation->version;
            } elseif ($version !== $reservation->version) {
                throw new StaleReservationException;
            }

            if ($nominationChanged) {
                $reservation = $updateNomination->execute($reservation, $isStaffRequested, $version, $user);
            }
        }

        return redirect()
            ->route('admin.reservations.edit', $reservation)
            ->with('success', __('messages.reservation.updated'));
    }

    public function cancel(
        Request $request,
        Reservation $reservation,
        ReservationService $reservationService,
    ): RedirectResponse {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $reservationService->cancel(
            $reservation,
            $this->normalizeNotes($validated['reason'] ?? null),
            $request->user(),
        );

        return back()->with('success', __('messages.reservation.canceled'));
    }

    public function complete(
        Request $request,
        Reservation $reservation,
        ReservationService $reservationService,
    ): RedirectResponse {
        // 通常の有償施術は「来店・会計」で実施内容と会計を確定する。ここ（会計なし完了）は
        // 無料・事前決済済み・回数券/月額利用の理由を明示した時だけ許可する（Task 11-27）。
        $validated = $request->validate([
            'exemption_reason' => ['required', Rule::enum(CheckoutExemptionReason::class)],
        ], ['exemption_reason.required' => __('messages.visit_completion.exemption_required')]);
        $reason = CheckoutExemptionReason::from($validated['exemption_reason']);
        $reservationService->markCompleted($reservation, $request->user(), $reason);

        return back()->with('success', __('messages.visit_completion.completed_without_checkout', ['reason' => $reason->label()]));
    }

    public function noShow(
        Request $request,
        Reservation $reservation,
        ReservationService $reservationService,
    ): RedirectResponse {
        $reservationService->markNoShow($reservation, $request->user());

        return back()->with('success', __('messages.reservation.no_show'));
    }

    private function userFor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    private function normalizeNotes(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
