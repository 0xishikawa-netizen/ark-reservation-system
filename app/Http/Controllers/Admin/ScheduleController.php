<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Notification\NotificationSettings;
use App\Domain\Reservation\RescheduleInput;
use App\Domain\Reservation\ReservationService;
use App\Enums\Reservation\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\ReservationNotificationDismissal;
use App\Models\User;
use App\Queries\ReservationFormOptionsQuery;
use App\Queries\ScheduleNotificationQuery;
use App\Queries\ScheduleQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class ScheduleController extends Controller
{
    public function index(
        Request $request,
        ScheduleQuery $query,
        ReservationFormOptionsQuery $optionsQuery,
        NotificationSettings $notificationSettings,
    ): Response {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'staff_id' => ['nullable', 'integer', 'exists:staff,user_id'],
            'view' => ['nullable', Rule::in(['day', 'week'])],
            'axis' => ['nullable', Rule::in(['staff', 'booth', 'both'])],
            // 台帳専用サイドパネルの状態（URL で状態を持たせる・§4-10）。
            'reservation' => ['nullable', 'integer', 'exists:reservations,id'],
            'customer' => ['nullable', 'integer', 'exists:customers,user_id'],
            'block' => ['nullable', 'integer', 'exists:staff_schedule_blocks,id'],
            'panel' => ['nullable', Rule::in(['search', 'create', 'block-create', 'slot-choice'])],
            // 新規予約モードの事前入力（空き枠クリック・再予約・§13, §17）。
            'pf_customer_id' => ['nullable', 'integer', 'exists:customers,user_id'],
            'pf_service_id' => ['nullable', 'integer', 'exists:services,id'],
            'pf_staff_id' => ['nullable', 'integer', 'exists:staff,user_id'],
            'pf_booth_id' => ['nullable', 'integer', 'exists:booths,id'],
            'pf_date' => ['nullable', 'date_format:Y-m-d'],
            'pf_time' => ['nullable', 'date_format:H:i'],
            // 予定ブロック作成モードの事前入力（空きセルクリック・§31-32）。
            'bf_staff_id' => ['nullable', 'integer', 'exists:staff,user_id'],
            'bf_booth_id' => ['nullable', 'integer', 'exists:booths,id'],
            'bf_date' => ['nullable', 'date_format:Y-m-d'],
            'bf_time' => ['nullable', 'date_format:H:i'],
        ]);
        $date = isset($validated['date'])
            ? CarbonImmutable::parse((string) $validated['date'])
            : CarbonImmutable::today();
        $staffId = isset($validated['staff_id']) ? (int) $validated['staff_id'] : null;
        $view = isset($validated['view']) ? (string) $validated['view'] : 'day';
        $axis = isset($validated['axis']) ? (string) $validated['axis'] : 'staff';
        $focusReservationId = isset($validated['reservation']) ? (int) $validated['reservation'] : null;
        $focusCustomerId = $focusReservationId === null && isset($validated['customer'])
            ? (int) $validated['customer']
            : null;
        $focusBlockId = $focusReservationId === null && $focusCustomerId === null && isset($validated['block'])
            ? (int) $validated['block']
            : null;
        $panelMode = $focusReservationId === null && $focusCustomerId === null && $focusBlockId === null
            ? ($validated['panel'] ?? null)
            : null;

        // get() を1回だけ呼ぶ（services/booths/popular_service_ids を毎回別々に叩くと
        // よく使うメニューの集計クエリまで重複してしまうため）。
        $reservationFormOptions = $optionsQuery->get();

        return Inertia::render('Admin/Schedule/Index', [
            ...$query->get($date, $staffId, $view, $axis, $request->user()?->can('sales.view') ?? false),
            'staff_options' => $optionsQuery->staff(),
            // メニュー別「本当に予約できる開始時刻」プレビュー用（§11）。
            'menu_options' => $reservationFormOptions['services'],
            'booth_options' => $reservationFormOptions['booths'],
            'popular_service_ids' => $reservationFormOptions['popular_service_ids'],
            'focus_reservation_id' => $focusReservationId,
            'focus_customer_id' => $focusCustomerId,
            'focus_block_id' => $focusBlockId,
            'panel_mode' => $panelMode,
            'create_prefill' => [
                'customer_id' => isset($validated['pf_customer_id']) ? (int) $validated['pf_customer_id'] : null,
                'service_id' => isset($validated['pf_service_id']) ? (int) $validated['pf_service_id'] : null,
                'staff_id' => isset($validated['pf_staff_id']) ? (int) $validated['pf_staff_id'] : null,
                'booth_id' => isset($validated['pf_booth_id']) ? (int) $validated['pf_booth_id'] : null,
                'date' => $validated['pf_date'] ?? null,
                'time' => $validated['pf_time'] ?? null,
            ],
            'block_create_prefill' => [
                'staff_id' => isset($validated['bf_staff_id']) ? (int) $validated['bf_staff_id'] : null,
                'booth_id' => isset($validated['bf_booth_id']) ? (int) $validated['bf_booth_id'] : null,
                'date' => $validated['bf_date'] ?? null,
                'time' => $validated['bf_time'] ?? null,
            ],
            // 新規予約の通知が届いた時に音を鳴らすか（設定 > 通知設定）。
            'notification_sound' => $notificationSettings->newReservationSound(),
            'filters' => [
                'date' => $date->toDateString(),
                'staff_id' => $staffId,
                'view' => $view,
                'axis' => $axis,
            ],
        ]);
    }

    /**
     * ドラッグ&ドロップで予約を移動する（§13-17, §27-32）。
     * 時間だけでなく、担当スタッフ・ブース・日付の変更にも対応する
     * （台帳の軸に応じて、別の行へドロップした場合のみ staff_id / booth_id を渡す）。
     * 既存の ReservationService::reschedule を再利用し、サーバー側で重複・勤務時間・
     * メニュー対応可否・ブース・営業時間・休業日・楽観ロックを最終再検証する（§32）。
     */
    public function updateReservationTime(
        Request $request,
        Reservation $reservation,
        ReservationService $reservationService,
    ): RedirectResponse {
        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
            'version' => ['required', 'integer', 'min:0'],
            // 未指定なら現状維持。null を明示的に渡すことはない（軸の対象外リソースは常に維持）。
            'staff_id' => ['nullable', 'integer', 'exists:staff,user_id'],
            'booth_id' => ['nullable', 'integer', 'exists:booths,id'],
        ]);

        if ($reservation->status !== ReservationStatus::Confirmed) {
            throw ValidationException::withMessages([
                'starts_at' => __('messages.reservation.board_move_confirmed_only'),
            ]);
        }

        if ($reservation->starts_at->isPast()) {
            throw ValidationException::withMessages([
                'starts_at' => __('messages.reservation.board_move_started'),
            ]);
        }

        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $staffId = $request->has('staff_id')
            ? (isset($validated['staff_id']) ? (int) $validated['staff_id'] : null)
            : ($reservation->staff_id === null ? null : (int) $reservation->staff_id);
        $boothId = $request->has('booth_id')
            ? (isset($validated['booth_id']) ? (int) $validated['booth_id'] : null)
            : ($reservation->booth_id === null ? null : (int) $reservation->booth_id);

        $reservationService->reschedule(new RescheduleInput(
            reservationId: (int) $reservation->id,
            staffId: $staffId,
            boothId: $boothId,
            startsAt: CarbonImmutable::parse((string) $validated['starts_at']),
            expectedVersion: (int) $validated['version'],
            actorUserId: (int) $user->id,
            adminContext: true,
        ));

        return back()->with('success', __('messages.reservation.moved'));
    }

    /**
     * オンライン予約通知の未読一覧（§34-37）。ポーリングで取得する
     * （ブロードキャスト基盤が未設定のため、リアルタイム push ではなくポーリングで近似する）。
     */
    public function notifications(Request $request, ScheduleNotificationQuery $query): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return response()->json([
            'notifications' => $query->unreadFor((int) $user->id),
        ]);
    }

    /**
     * 通知を「×で閉じた」ことを記録する。同一管理者には再表示しない（§37）。
     */
    public function dismissNotification(Request $request, Reservation $reservation): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        ReservationNotificationDismissal::query()->firstOrCreate(
            ['user_id' => $user->id, 'reservation_id' => $reservation->id],
            ['dismissed_at' => now()],
        );

        return response()->json(['ok' => true]);
    }
}
