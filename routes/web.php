<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BoothController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\CustomerMembershipController as AdminCustomerMembershipController;
use App\Http\Controllers\Admin\CustomerTicketController;
use App\Http\Controllers\Admin\FailedJobsController;
use App\Http\Controllers\Admin\Integrations\ReservationIntegrationController;
use App\Http\Controllers\Admin\MembershipPlanController;
use App\Http\Controllers\Admin\MfaController;
use App\Http\Controllers\Admin\NotificationSettingsController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\ReservationController as AdminReservationController;
use App\Http\Controllers\Admin\ReservationPolicySettingsController;
use App\Http\Controllers\Admin\RolePermissionController;
use App\Http\Controllers\Admin\ScheduleBlockController;
use App\Http\Controllers\Admin\ScheduleController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\StaffShiftController;
use App\Http\Controllers\Admin\SystemStatusController;
use App\Http\Controllers\Admin\TicketPolicySettingsController;
use App\Http\Controllers\Admin\TicketProductController;
use App\Http\Controllers\Admin\TwoFactorSetupController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Booking\BookingConfirmationController;
use App\Http\Controllers\Booking\BookingController;
use App\Http\Controllers\Booking\BookingFindController;
use App\Http\Controllers\Booking\BookingMemberUpgradeController;
use App\Http\Controllers\Booking\BookingPaymentController;
use App\Http\Controllers\Customer\MembershipController as CustomerMembershipController;
use App\Http\Controllers\Customer\PaymentController as CustomerPaymentController;
use App\Http\Controllers\Customer\PaymentHistoryController as CustomerPaymentHistoryController;
use App\Http\Controllers\Customer\ProfileController;
use App\Http\Controllers\Customer\ReservationAddonPaymentController;
use App\Http\Controllers\Customer\ReservationController as CustomerReservationController;
use App\Http\Controllers\Customer\SecurityController as CustomerSecurityController;
use App\Http\Controllers\Customer\TicketController as CustomerTicketPageController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Reserve\ReserveController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Middleware\AdminAccess;
use App\Http\Middleware\AdminIdleTimeout;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureStaffMfa;
use App\Http\Middleware\ThrottleFortifyRequests;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

// Stripe webhook。署名検証で認証するため auth を掛けない。
// CSRF は bootstrap/app.php で除外。Stripe の retry を阻害する rate limit は付けない。
Route::post('stripe/webhook', StripeWebhookController::class)
    ->withoutMiddleware([ThrottleFortifyRequests::class])
    ->name('stripe.webhook');

// Google ログイン（Phase 9.6 / Socialite）。stateful（state 検証あり）。
Route::middleware('web')->group(function (): void {
    Route::get('auth/google/redirect', [GoogleAuthController::class, 'redirect'])
        ->middleware('throttle:google-oauth')
        ->name('auth.google.redirect');
    Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])
        ->middleware('throttle:google-oauth')
        ->name('auth.google.callback');
    // 「既存アカウントがあります」→ パスワード確認して連携。
    Route::get('auth/google/confirm', [GoogleAuthController::class, 'confirm'])
        ->name('auth.google.confirm');
    Route::post('auth/google/link-existing', [GoogleAuthController::class, 'linkExisting'])
        ->middleware('throttle:6,1')
        ->name('auth.google.link-existing');

    // ログイン済みユーザーの連携 / 解除（再認証必須）。
    Route::middleware('auth')->group(function (): void {
        Route::get('auth/google/link', [GoogleAuthController::class, 'startLink'])
            ->middleware(['password.confirm', 'throttle:google-oauth'])
            ->name('auth.google.link');
        Route::delete('auth/google/unlink', [GoogleAuthController::class, 'unlink'])
            ->middleware(['password.confirm', 'throttle:6,1'])
            ->name('auth.google.unlink');
    });
});

// 未ログイン予約。会員向け reserve / mypage とは認証境界を分ける。
Route::middleware('web')->group(function (): void {
    Route::get('booking', [BookingController::class, 'create'])
        ->name('booking.create');
    Route::get('booking/find', [BookingFindController::class, 'show'])
        ->name('booking.find.show');
    Route::post('booking/find/send-code', [BookingFindController::class, 'sendCode'])
        ->middleware('throttle:guest-lookup')
        ->name('booking.find.sendCode');
    Route::post('booking/find/verify', [BookingFindController::class, 'verify'])
        ->middleware('throttle:guest-lookup')
        ->name('booking.find.verify');
    Route::get('booking/availability', [BookingController::class, 'availability'])
        ->name('booking.availability');
    Route::get('booking/availability/week', [BookingController::class, 'weekAvailability'])
        ->name('booking.availability.week');
    Route::post('booking', [BookingController::class, 'store'])
        ->middleware('throttle:guest-reserve')
        ->name('booking.store');
    Route::get('booking/confirmation/{selector}', [BookingConfirmationController::class, 'show'])
        ->name('booking.confirmation.show');
    Route::get('booking/confirmation/{selector}/checkout', [BookingPaymentController::class, 'show'])
        ->name('booking.confirmation.checkout');
    Route::post('booking/confirmation/{selector}/payment/sync', [BookingPaymentController::class, 'sync'])
        ->middleware('throttle:guest-reserve')
        ->name('booking.confirmation.payment.sync');
    Route::put('booking/confirmation/{selector}', [BookingConfirmationController::class, 'reschedule'])
        ->middleware('throttle:guest-reserve')
        ->name('booking.confirmation.reschedule');
    Route::delete('booking/confirmation/{selector}', [BookingConfirmationController::class, 'cancel'])
        ->middleware('throttle:guest-reserve')
        ->name('booking.confirmation.cancel');
    Route::post('booking/confirmation/{selector}/register-as-member', [BookingMemberUpgradeController::class, 'store'])
        ->middleware('throttle:guest-reserve')
        ->name('booking.confirmation.upgrade');
});

Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
    Route::get('reserve', [ReserveController::class, 'create'])
        ->name('reserve.create');
    Route::get('reserve/availability', [ReserveController::class, 'availability'])
        ->name('reserve.availability');
    Route::get('reserve/availability/week', [ReserveController::class, 'weekAvailability'])
        ->name('reserve.availability.week');
    Route::post('reserve', [ReserveController::class, 'store'])
        ->middleware('throttle:reserve')
        ->name('reserve.store');
});

Route::middleware(['web', 'auth', 'verified'])
    ->prefix('mypage')->name('mypage.')->group(function (): void {
        Route::get('reservations', [CustomerReservationController::class, 'index'])
            ->name('reservations.index');
        Route::get('reservations/{reservation}', [CustomerReservationController::class, 'show'])
            ->name('reservations.show');
        Route::put('reservations/{reservation}', [CustomerReservationController::class, 'update'])
            ->middleware('throttle:reserve')
            ->name('reservations.update');
        Route::delete('reservations/{reservation}', [CustomerReservationController::class, 'destroy'])
            ->name('reservations.destroy');
        Route::get('reservations/{reservation}/checkout', [CustomerPaymentController::class, 'show'])
            ->name('reservations.checkout');
        Route::post('reservations/{reservation}/payment/sync', [CustomerPaymentController::class, 'sync'])
            ->middleware('throttle:reserve')
            ->name('reservations.payment.sync');
        Route::get('reservations/{reservation}/addon/checkout', [ReservationAddonPaymentController::class, 'show'])
            ->name('reservations.addon.checkout');
        Route::post('reservations/{reservation}/addon/payment/sync', [ReservationAddonPaymentController::class, 'sync'])
            ->middleware('throttle:reserve')
            ->name('reservations.addon.payment.sync');
        Route::get('tickets', [CustomerTicketPageController::class, 'index'])
            ->name('tickets.index');
        Route::get('payments', [CustomerPaymentHistoryController::class, 'index'])
            ->name('payments.index');
        Route::get('membership', [CustomerMembershipController::class, 'show'])
            ->name('membership.show');
        Route::post('membership/subscribe', [CustomerMembershipController::class, 'subscribe'])
            ->middleware('throttle:reserve')
            ->name('membership.subscribe');
        // 3DS/SCA 確認画面（自分の進行中申込のみ）と、認証完了後の状態取り込み。
        Route::get('membership/confirm', [CustomerMembershipController::class, 'confirm'])
            ->name('membership.confirm');
        Route::post('membership/payment/sync', [CustomerMembershipController::class, 'syncPayment'])
            ->middleware('throttle:reserve')
            ->name('membership.payment.sync');
        Route::post('membership/cancel', [CustomerMembershipController::class, 'cancel'])
            ->name('membership.cancel');
        Route::post('membership/resume', [CustomerMembershipController::class, 'resume'])
            ->name('membership.resume');
        Route::put('membership/payment-method', [CustomerMembershipController::class, 'updatePaymentMethod'])
            ->name('membership.payment-method');
        Route::get('security', [CustomerSecurityController::class, 'show'])
            ->name('security.show');
        Route::get('profile', [ProfileController::class, 'show'])
            ->name('profile.show');
        Route::get('profile/edit', [ProfileController::class, 'edit'])
            ->name('profile.edit');
        Route::put('profile', [ProfileController::class, 'update'])
            ->name('profile.update');
    });

Route::middleware([
    'web',
    'auth',
    'verified',
    AdminAccess::class,
    EnsureAccountIsActive::class,
    AdminIdleTimeout::class,
    EnsureStaffMfa::class,
])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/', AdminDashboardController::class)->name('dashboard');
    Route::get('two-factor-setup', [TwoFactorSetupController::class, 'show'])
        ->name('two-factor-setup');
    // MFA 管理（TOTP / SMS フォールバック / Google 連携）。自分の資格情報のみ。
    Route::get('mfa', [MfaController::class, 'show'])->name('mfa.show');
    // 電話番号の登録・変更は機微操作: 再認証 + OTP 検証 + 監査
    Route::post('mfa/phone', [MfaController::class, 'startPhoneVerification'])
        ->middleware(['password.confirm', 'throttle:6,1'])
        ->name('mfa.phone.start');
    Route::post('mfa/phone/verify', [MfaController::class, 'verifyPhone'])
        ->middleware(['password.confirm', 'throttle:6,1'])
        ->name('mfa.phone.verify');
    Route::get('reservations', [AdminReservationController::class, 'index'])
        ->middleware('can:reservations.view')
        ->name('reservations.index');
    Route::get('schedule', [ScheduleController::class, 'index'])
        ->middleware('can:reservations.view')
        ->name('schedule.index');
    // 予約台帳のドラッグ&ドロップによる時間変更（§13-17）。既存の reschedule Service を再利用。
    Route::put('schedule/reservations/{reservation}/time', [ScheduleController::class, 'updateReservationTime'])
        ->middleware('can:reservations.manage')
        ->name('schedule.reservations.time');
    // オンライン予約通知（§34-37）。ポーリングで取得・既読は管理者ごとに DB へ記録。
    Route::get('schedule/notifications', [ScheduleController::class, 'notifications'])
        ->middleware('can:reservations.view')
        ->name('schedule.notifications');
    Route::post('schedule/notifications/{reservation}/dismiss', [ScheduleController::class, 'dismissNotification'])
        ->middleware('can:reservations.view')
        ->name('schedule.notifications.dismiss');
    // 予定ブロック（予約以外でスタッフ/ブースの時間を埋める・§27-46）。
    Route::post('schedule/blocks', [ScheduleBlockController::class, 'store'])
        ->middleware('can:reservations.manage')
        ->name('schedule.blocks.store');
    Route::put('schedule/blocks/{block}', [ScheduleBlockController::class, 'update'])
        ->middleware('can:reservations.manage')
        ->name('schedule.blocks.update');
    Route::put('schedule/blocks/{block}/time', [ScheduleBlockController::class, 'updateTime'])
        ->middleware('can:reservations.manage')
        ->name('schedule.blocks.time');
    Route::delete('schedule/blocks/{block}', [ScheduleBlockController::class, 'destroy'])
        ->middleware('can:reservations.manage')
        ->name('schedule.blocks.destroy');
    // 検索は更新を伴わない閲覧操作であり、予約カードから同じ顧客情報を見られるため
    // reservations.view に統一する（reservations.manage は新規予約/編集/D&D等の更新操作用）。
    Route::get('reservations/customer-search', [AdminReservationController::class, 'customerSearch'])
        ->middleware('can:reservations.view')
        ->name('reservations.customer-search');
    // 電話予約などで未登録のお客様の予約を取るための仮登録（§新規のお客様）。
    // 顧客レコードを作る更新操作なので reservations.manage を要求する。
    Route::post('reservations/provisional-customer', [AdminReservationController::class, 'storeProvisionalCustomer'])
        ->middleware('can:reservations.manage')
        ->name('reservations.provisional-customer');
    // 予約台帳の顧客・予約詳細パネル用の集約データ（§17）。閲覧権限のみ。
    Route::get('reservations/{reservation}/panel', [AdminReservationController::class, 'panel'])
        ->middleware('can:reservations.view')
        ->name('reservations.panel');
    Route::get('reservations/availability', [AdminReservationController::class, 'availability'])
        ->middleware('can:reservations.manage')
        ->name('reservations.availability');
    Route::get('reservations/available-booth', [AdminReservationController::class, 'availableBooth'])
        ->middleware('can:reservations.manage')
        ->name('reservations.available-booth');
    Route::post('reservations', [AdminReservationController::class, 'store'])
        ->middleware('can:reservations.manage')
        ->name('reservations.store');
    Route::get('reservations/{reservation}/edit', [AdminReservationController::class, 'edit'])
        ->middleware('can:reservations.manage')
        ->name('reservations.edit');
    Route::put('reservations/{reservation}', [AdminReservationController::class, 'update'])
        ->middleware('can:reservations.manage')
        ->name('reservations.update');
    Route::post('reservations/{reservation}/adjustment', [AdminReservationController::class, 'adjustment'])
        ->middleware(['can:reservations.manage', 'password.confirm'])
        ->name('reservations.adjustment');
    Route::patch('reservations/{reservation}/cancel', [AdminReservationController::class, 'cancel'])
        ->middleware('can:reservations.manage')
        ->name('reservations.cancel');
    Route::patch('reservations/{reservation}/complete', [AdminReservationController::class, 'complete'])
        ->middleware('can:reservations.manage')
        ->name('reservations.complete');
    Route::patch('reservations/{reservation}/no-show', [AdminReservationController::class, 'noShow'])
        ->middleware('can:reservations.manage')
        ->name('reservations.no-show');
    Route::get('customers', [CustomerController::class, 'index'])
        ->name('customers.index');
    Route::get('customers/{customer}/summary', [CustomerController::class, 'summary'])
        ->middleware('can:customers.view')
        ->name('customers.summary');
    // 予約台帳サイドパネルの顧客検索結果から開く版（§15-16）。台帳と同じ閲覧権限。
    Route::get('customers/{customer}/board-panel', [CustomerController::class, 'boardPanel'])
        ->middleware('can:reservations.view')
        ->name('customers.board-panel');
    Route::get('customers/{customer}/tickets', [CustomerTicketController::class, 'show'])
        ->middleware('can:customers.view')
        ->name('customers.tickets');
    Route::get('customers/{customer}/membership', [AdminCustomerMembershipController::class, 'show'])
        ->middleware('can:customers.view')
        ->name('customers.membership');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])
        ->name('customers.show');
    Route::get('customers/{customer}/edit', [CustomerController::class, 'edit'])
        ->name('customers.edit');
    Route::put('customers/{customer}', [CustomerController::class, 'update'])
        ->name('customers.update');
    // 台帳の顧客・予約詳細パネルからメモだけを素早く追加・編集する（§8）。
    Route::patch('customers/{customer}/note', [CustomerController::class, 'updateNote'])
        ->name('customers.update-note');
    Route::get('staff', [StaffController::class, 'index'])
        ->middleware('can:staff.manage')
        ->name('staff.index');
    Route::get('staff/create', [StaffController::class, 'create'])
        ->middleware(['can:staff.manage', 'password.confirm'])
        ->name('staff.create');
    Route::post('staff', [StaffController::class, 'store'])
        ->middleware(['can:staff.manage', 'password.confirm'])
        ->name('staff.store');
    Route::get('staff/{staff}/edit', [StaffController::class, 'edit'])
        ->middleware(['can:staff.manage', 'password.confirm'])
        ->name('staff.edit');
    Route::put('staff/{staff}', [StaffController::class, 'update'])
        ->middleware(['can:staff.manage', 'password.confirm'])
        ->name('staff.update');
    Route::patch('staff/{staff}/deactivate', [StaffController::class, 'deactivate'])
        ->middleware(['can:staff.manage', 'password.confirm'])
        ->name('staff.deactivate');
    Route::middleware('can:shifts.manage')->group(function (): void {
        Route::get('staff-shifts', [StaffShiftController::class, 'index'])
            ->name('staff-shifts.index');
        Route::post('staff-shifts', [StaffShiftController::class, 'store'])
            ->name('staff-shifts.store');

        // 基本シフト / 例外日 / 予約受付 / 生成（#11）。{staffShift} より前に登録する。
        Route::put('staff-shifts/templates', [StaffShiftController::class, 'saveTemplates'])
            ->name('staff-shifts.templates.save');
        Route::post('staff-shifts/exceptions', [StaffShiftController::class, 'saveException'])
            ->name('staff-shifts.exceptions.save');
        Route::delete('staff-shifts/exceptions/{exception}', [StaffShiftController::class, 'clearException'])
            ->name('staff-shifts.exceptions.clear');
        Route::post('staff-shifts/generate', [StaffShiftController::class, 'generate'])
            ->name('staff-shifts.generate');
        Route::put('staff-shifts/booking', [StaffShiftController::class, 'updateBooking'])
            ->name('staff-shifts.booking.update');

        Route::put('staff-shifts/{staffShift}', [StaffShiftController::class, 'update'])
            ->name('staff-shifts.update');
        Route::delete('staff-shifts/{staffShift}', [StaffShiftController::class, 'destroy'])
            ->name('staff-shifts.destroy');
    });
    Route::middleware('can:services.manage')->group(function (): void {
        Route::resource('services', ServiceController::class)
            ->only(['index', 'create', 'store', 'edit', 'update']);
        Route::patch('services/{service}/active', [ServiceController::class, 'setActive'])
            ->name('services.set-active');
    });
    Route::middleware('can:ticket_products.manage')->group(function (): void {
        Route::get('ticket-products', [TicketProductController::class, 'index'])
            ->name('ticket-products.index');
        Route::get('ticket-products/create', [TicketProductController::class, 'create'])
            ->name('ticket-products.create');
        Route::post('ticket-products', [TicketProductController::class, 'store'])
            ->name('ticket-products.store');
        Route::get('ticket-products/{ticketProduct}/edit', [TicketProductController::class, 'edit'])
            ->name('ticket-products.edit');
        Route::put('ticket-products/{ticketProduct}', [TicketProductController::class, 'update'])
            ->name('ticket-products.update');
        Route::patch('ticket-products/{ticketProduct}/active', [TicketProductController::class, 'setActive'])
            ->name('ticket-products.set-active');
    });
    Route::middleware('can:membership.manage')->group(function (): void {
        Route::get('membership-plans', [MembershipPlanController::class, 'index'])
            ->name('membership-plans.index');
        Route::get('membership-plans/create', [MembershipPlanController::class, 'create'])
            ->name('membership-plans.create');
        Route::get('membership-plans/{membershipPlan}/edit', [MembershipPlanController::class, 'edit'])
            ->name('membership-plans.edit');
        // 価格・付与回数・Stripe price ID・有効状態は以後の課金契約と GRANT の条件に直結する。
        // 機微操作としてパスワード再確認を必須にする（docs/tasks/phase-06.md §15）。
        Route::middleware('password.confirm')->group(function (): void {
            Route::post('membership-plans', [MembershipPlanController::class, 'store'])
                ->name('membership-plans.store');
            Route::put('membership-plans/{membershipPlan}', [MembershipPlanController::class, 'update'])
                ->name('membership-plans.update');
            Route::patch('membership-plans/{membershipPlan}/active', [MembershipPlanController::class, 'setActive'])
                ->name('membership-plans.set-active');
        });
    });
    Route::middleware(['can:membership.manage', 'password.confirm'])->group(function (): void {
        Route::post('memberships/{membership}/adjust', [AdminCustomerMembershipController::class, 'adjust'])
            ->name('memberships.adjust');
        Route::post('memberships/{membership}/cancel-now', [AdminCustomerMembershipController::class, 'cancelNow'])
            ->name('memberships.cancel-now');
    });
    Route::post('memberships/{membership}/sync', [AdminCustomerMembershipController::class, 'sync'])
        ->middleware('can:membership.manage')
        ->name('memberships.sync');
    Route::middleware(['can:ticket.grant', 'password.confirm'])->group(function (): void {
        Route::post('customers/{customer}/tickets/grant', [CustomerTicketController::class, 'grant'])
            ->name('customers.tickets.grant');
        Route::post('ticket-wallets/{ticketWallet}/revoke', [CustomerTicketController::class, 'revoke'])
            ->name('ticket-wallets.revoke');
        Route::post('ticket-wallets/{ticketWallet}/adjust', [CustomerTicketController::class, 'adjust'])
            ->name('ticket-wallets.adjust');
    });
    Route::middleware('can:booths.manage')->group(function (): void {
        Route::resource('booths', BoothController::class)
            ->only(['index', 'create', 'store', 'edit', 'update']);
        Route::patch('booths/{booth}/active', [BoothController::class, 'setActive'])
            ->name('booths.set-active');
    });
    Route::middleware('can:reservations.view')->group(function (): void {
        Route::get('payments', [AdminPaymentController::class, 'index'])
            ->name('payments.index');
        Route::get('payments/{payment}', [AdminPaymentController::class, 'show'])
            ->name('payments.show');
    });
    Route::post('payments/{payment}/sync', [AdminPaymentController::class, 'sync'])
        ->middleware('can:reservations.manage')
        ->name('payments.sync');
    // 返金は機微操作: manager 以上 + 専用 permission + パスワード再確認 + 理由必須 + 監査
    Route::post('payments/{payment}/refund', [AdminPaymentController::class, 'refund'])
        ->middleware(['can:refund.execute', 'password.confirm'])
        ->name('payments.refund');
    Route::get('system/failed-jobs', FailedJobsController::class)
        ->middleware('can:failed_jobs.view')
        ->name('system.failed-jobs');
    Route::get('system/status', [SystemStatusController::class, 'show'])
        ->middleware('can:failed_jobs.view')
        ->name('system.status');
    Route::get('system/audit-logs', [AuditLogController::class, 'index'])
        ->middleware('can:audit_logs.view')
        ->name('system.audit-logs');

    // Phase 9 外部予約連携ステータス（店舗スタッフ向け read-only）
    Route::get('integrations/reservations', [ReservationIntegrationController::class, 'show'])
        ->middleware('can:integrations.view')
        ->name('integrations.reservations');
    Route::post('integrations/reservations/outbox/{reservationSyncOutbox}/retry', [ReservationIntegrationController::class, 'retryOutbox'])
        ->middleware(['can:integrations.manage', 'password.confirm'])
        ->name('integrations.reservations.outbox.retry');
    Route::get('settings/tickets', [TicketPolicySettingsController::class, 'show'])
        ->middleware('can:ticket_policy.manage')
        ->name('settings.tickets.show');
    Route::patch('settings/tickets', [TicketPolicySettingsController::class, 'update'])
        ->middleware(['can:ticket_policy.manage', 'password.confirm'])
        ->name('settings.tickets.update');
    Route::get('settings/reservation', [ReservationPolicySettingsController::class, 'show'])
        ->middleware('can:settings.manage')
        ->name('settings.reservation.show');
    Route::patch('settings/reservation', [ReservationPolicySettingsController::class, 'update'])
        ->middleware(['can:settings.manage', 'password.confirm'])
        ->name('settings.reservation.update');
    // 新規予約の通知音など、管理画面の通知設定（金額・権限に関わらないため再パスワード確認は不要）。
    Route::get('settings/notifications', [NotificationSettingsController::class, 'show'])
        ->middleware('can:settings.manage')
        ->name('settings.notifications.show');
    Route::patch('settings/notifications', [NotificationSettingsController::class, 'update'])
        ->middleware('can:settings.manage')
        ->name('settings.notifications.update');
    Route::get('settings/roles', [RolePermissionController::class, 'show'])
        ->middleware(['can:roles.manage', 'password.confirm'])
        ->name('settings.roles.show');
    Route::patch('settings/roles', [RolePermissionController::class, 'update'])
        ->middleware(['can:roles.manage', 'password.confirm'])
        ->name('settings.roles.update');
});
