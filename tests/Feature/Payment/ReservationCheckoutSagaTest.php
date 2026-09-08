<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Payment\Gateway\Dto\PaymentIntentResult;
use App\Domain\Payment\Gateway\FakeStripeGateway;
use App\Domain\Payment\PaymentService;
use App\Domain\Payment\ReservationCheckoutSaga;
use App\Domain\Reservation\ReservationInput;
use App\Domain\Reservation\ReservationService;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Reservation\PaymentMethod;
use App\Enums\Reservation\PaymentStatus as ReservationPaymentStatus;
use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ReservationStatus;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationResourceSlot;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\TicketProduct;
use App\Models\TicketTransaction;
use App\Models\TicketWallet;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use App\Domain\Ticket\TicketLedgerService;
use App\Enums\Ticket\TicketTransactionType;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReservationCheckoutSagaTest extends TestCase
{
    // RefreshDatabase はテスト全体を transaction で包むため使わない。
    // Stripe 呼び出しが transaction 外であることを実際に検証するには transactionLevel()===0 が必要。
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 12:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-09-08 12:00:00'));
        config()->set('reservation.slot_minutes', 15);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------- 予約作成時の分岐（Ticket 非退行） ----------

    public function test_card_reservation_starts_as_pending_payment_with_deadline(): void
    {
        $reservation = $this->createCardReservation();

        $this->assertSame(ReservationStatus::PendingPayment, $reservation->status);
        $this->assertSame(ReservationPaymentStatus::PendingPayment, $reservation->payment_status);
        $this->assertNotNull($reservation->payment_expires_at);
        // settings('reservation.hold_minutes') = 10（seed 値）
        $this->assertSame(
            CarbonImmutable::parse('2026-09-08 12:10:00')->toDateTimeString(),
            $reservation->payment_expires_at->toDateTimeString(),
        );
    }

    public function test_payment_deadline_follows_settings_not_a_hardcoded_value(): void
    {
        app(Settings::class)->set('reservation.hold_minutes', 25, 'int');

        $reservation = $this->createCardReservation();

        $this->assertSame(
            CarbonImmutable::parse('2026-09-08 12:25:00')->toDateTimeString(),
            $reservation->payment_expires_at->toDateTimeString(),
        );
    }

    public function test_card_reservation_does_not_create_any_ticket_hold(): void
    {
        [$customer, $service, $staff] = $this->masters();
        $this->grantTicket($customer);

        $reservation = $this->createCardReservation($customer, $service, $staff);

        $this->assertSame(0, TicketTransaction::query()
            ->where('reservation_id', $reservation->id)
            ->count());
    }

    public function test_ticket_reservation_never_calls_stripe(): void
    {
        [$customer, $service, $staff] = $this->masters();
        $this->grantTicket($customer);
        $gateway = $this->fakeGateway();

        $reservation = app(ReservationService::class)->create($this->input(
            $customer, $service, $staff, PaymentMethod::Ticket,
        ));

        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertSame([], $gateway->calls());
        $this->assertSame(0, Payment::query()->count());
    }

    // ---------- checkout ----------

    public function test_start_checkout_creates_one_payment_and_returns_client_secret(): void
    {
        $reservation = $this->createCardReservation();
        $gateway = $this->fakeGateway();

        $session = $this->saga()->startCheckout($reservation);

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(PaymentStatus::Pending, $session->payment->status);
        $this->assertNotNull($session->clientSecret);
        $this->assertCount(1, $gateway->callsFor(FakeStripeGateway::CREATE));
        // client_secret を DB に保存していないこと
        $this->assertStringNotContainsString(
            (string) $session->clientSecret,
            json_encode($session->payment->getAttributes(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_restarting_checkout_reuses_operation_id_and_creates_no_second_intent(): void
    {
        $reservation = $this->createCardReservation();
        $gateway = $this->fakeGateway();

        $first = $this->saga()->startCheckout($reservation);
        $second = $this->saga()->startCheckout($reservation->refresh());

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(
            $first->payment->payment_operation_id,
            $second->payment->payment_operation_id,
        );
        // 2 回目は create ではなく retrieve になる（二重 PaymentIntent を作らない）
        $this->assertCount(1, $gateway->callsFor(FakeStripeGateway::CREATE));
    }

    public function test_authorized_does_not_confirm_reservation_and_capture_does(): void
    {
        $reservation = $this->createCardReservation();
        $gateway = $this->fakeGateway();
        $session = $this->saga()->startCheckout($reservation);
        $payment = $session->payment;

        // Stripe 側で与信のみ成立した状態
        $gateway->setPaymentIntent($this->intent($payment, 'requires_capture', capturable: 5000));
        $payment = app(PaymentService::class)->syncFromStripe($payment);
        $this->saga()->reflectOnReservation($payment);

        $reservation->refresh();
        $this->assertSame(PaymentStatus::Authorized, $payment->status);
        $this->assertSame(ReservationStatus::PendingPayment, $reservation->status, '与信だけで予約を確定してはいけない');
        $this->assertSame(ReservationPaymentStatus::Authorized, $reservation->payment_status);

        // capture 後にのみ確定する
        $payment = $this->saga()->syncAndAdvance($payment);
        $reservation->refresh();

        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertSame(ReservationPaymentStatus::Paid, $reservation->payment_status);
        $this->assertNull($reservation->payment_expires_at);
        $this->assertNotNull($payment->paid_at);
    }

    // ---------- 期限切れ ----------

    public function test_expiry_voids_authorization_releases_slots_and_expires_reservation(): void
    {
        $reservation = $this->createCardReservation();
        $gateway = $this->fakeGateway();
        $payment = $this->saga()->startCheckout($reservation)->payment;

        $gateway->setPaymentIntent($this->intent($payment, 'requires_capture', capturable: 5000));
        $payment = app(PaymentService::class)->syncFromStripe($payment);
        $this->assertSame(PaymentStatus::Authorized, $payment->status);
        $this->assertGreaterThan(0, $this->slotCount($reservation));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 12:11:00'));
        Carbon::setTestNow(Carbon::parse('2026-09-08 12:11:00'));

        $this->artisan('payments:expire')->assertSuccessful();

        $reservation->refresh();
        $payment->refresh();

        $this->assertSame(ReservationStatus::Expired, $reservation->status);
        $this->assertSame(ReservationPaymentStatus::Voided, $reservation->payment_status);
        $this->assertSame(PaymentStatus::Voided, $payment->status);
        $this->assertNotNull($payment->voided_at);
        $this->assertSame(0, $this->slotCount($reservation), '枠が解放されていない');
        $this->assertCount(1, $gateway->callsFor(FakeStripeGateway::CANCEL));
    }

    public function test_expiry_is_idempotent_and_does_not_double_void_or_double_release(): void
    {
        $reservation = $this->createCardReservation();
        $gateway = $this->fakeGateway();
        $payment = $this->saga()->startCheckout($reservation)->payment;
        $gateway->setPaymentIntent($this->intent($payment, 'requires_capture', capturable: 5000));
        app(PaymentService::class)->syncFromStripe($payment);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 12:11:00'));
        Carbon::setTestNow(Carbon::parse('2026-09-08 12:11:00'));

        $this->artisan('payments:expire')->assertSuccessful();
        $this->artisan('payments:expire')->assertSuccessful();
        $this->saga()->expireReservation($reservation->refresh());

        $this->assertSame(ReservationStatus::Expired, $reservation->refresh()->status);
        $this->assertSame(PaymentStatus::Voided, $payment->refresh()->status);
        $this->assertCount(1, $gateway->callsFor(FakeStripeGateway::CANCEL), 'cancel が二重に呼ばれた');
        $this->assertSame(0, $this->slotCount($reservation));
    }

    public function test_captured_payment_at_expiry_is_flagged_and_never_auto_refunded(): void
    {
        $reservation = $this->createCardReservation();
        $gateway = $this->fakeGateway();
        $payment = $this->saga()->startCheckout($reservation)->payment;

        // 期限直前に capture が成立していた
        $gateway->setPaymentIntent($this->intent($payment, 'succeeded', received: 5000));
        $payment = app(PaymentService::class)->syncFromStripe($payment);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 12:11:00'));
        Carbon::setTestNow(Carbon::parse('2026-09-08 12:11:00'));

        // 予約は pending_payment のままにして期限処理へ流す
        Reservation::query()->whereKey($reservation->getKey())->update([
            'status' => ReservationStatus::PendingPayment->value,
        ]);

        $this->saga()->expireReservation($reservation->refresh());

        $payment->refresh();
        $this->assertTrue((bool) $payment->needs_attention, '要対応フラグが立っていない');
        $this->assertSame('captured_after_expiry', $payment->failure_code);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status, '自動返金してはいけない');
        $this->assertCount(0, $gateway->callsFor(FakeStripeGateway::REFUND), '自動返金が実行された');
        $this->assertSame(ReservationStatus::PendingPayment, $reservation->refresh()->status);
    }

    public function test_expiry_leaves_pending_without_intent_voided_locally(): void
    {
        $reservation = $this->createCardReservation();
        $gateway = $this->fakeGateway();

        // PaymentIntent 未作成の pending（Stripe を一度も呼べていない）
        $payment = Payment::factory()->create([
            'customer_id' => $reservation->customer_id,
            'reservation_id' => $reservation->id,
            'status' => PaymentStatus::Pending->value,
            'stripe_payment_intent_id' => null,
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 12:11:00'));
        Carbon::setTestNow(Carbon::parse('2026-09-08 12:11:00'));

        $this->artisan('payments:expire')->assertSuccessful();

        $this->assertSame(PaymentStatus::Voided, $payment->refresh()->status);
        $this->assertSame(ReservationStatus::Expired, $reservation->refresh()->status);
        $this->assertSame([], $gateway->calls(), 'PaymentIntent が無いのに Stripe を呼んだ');
    }

    public function test_confirmed_reservation_is_never_expired(): void
    {
        $reservation = $this->createCardReservation();
        $gateway = $this->fakeGateway();
        $payment = $this->saga()->startCheckout($reservation)->payment;
        $gateway->setPaymentIntent($this->intent($payment, 'requires_capture', capturable: 5000));
        $this->saga()->syncAndAdvance($payment);

        $this->assertSame(ReservationStatus::Confirmed, $reservation->refresh()->status);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 13:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-09-08 13:00:00'));
        $this->artisan('payments:expire')->assertSuccessful();

        $this->assertSame(ReservationStatus::Confirmed, $reservation->refresh()->status);
        $this->assertSame(PaymentStatus::Succeeded, $payment->refresh()->status);
    }

    // ---------- helpers ----------

    private function saga(): ReservationCheckoutSaga
    {
        return app(ReservationCheckoutSaga::class);
    }

    private function fakeGateway(): FakeStripeGateway
    {
        return app(FakeStripeGateway::class);
    }

    private function intent(
        Payment $payment,
        string $status,
        int $capturable = 0,
        int $received = 0,
    ): PaymentIntentResult {
        return new PaymentIntentResult(
            id: (string) $payment->stripe_payment_intent_id,
            status: $status,
            amount: (int) $payment->amount,
            amountCapturable: $capturable,
            amountReceived: $received,
            currency: (string) $payment->currency,
            chargeId: $status === 'succeeded' ? 'ch_fake_1' : null,
        );
    }

    private function slotCount(Reservation $reservation): int
    {
        return ReservationResourceSlot::query()
            ->where('reservation_id', $reservation->id)
            ->count();
    }

    /** @return array{0: Customer, 1: Service, 2: Staff} */
    private function masters(): array
    {
        $customer = Customer::factory()->create();
        $service = Service::factory()->create([
            'duration_min' => 60,
            'price' => 5000,
            'requires_staff' => true,
            'is_active' => true,
            'is_online_bookable' => true,
        ]);
        $staff = Staff::factory()->create(['is_bookable' => true]);
        $service->staff()->attach($staff->user_id);
        StaffShift::query()->create([
            'staff_id' => $staff->user_id,
            'work_date' => '2026-10-01',
            'start_at' => '09:00:00',
            'end_at' => '18:00:00',
        ]);

        return [$customer, $service, $staff];
    }

    private function createCardReservation(
        ?Customer $customer = null,
        ?Service $service = null,
        ?Staff $staff = null,
    ): Reservation {
        if ($customer === null || $service === null || $staff === null) {
            [$customer, $service, $staff] = $this->masters();
        }

        return app(ReservationService::class)->create(
            $this->input($customer, $service, $staff, PaymentMethod::Single),
        );
    }

    private function input(
        Customer $customer,
        Service $service,
        Staff $staff,
        PaymentMethod $method,
    ): ReservationInput {
        return new ReservationInput(
            customerId: (int) $customer->user_id,
            serviceId: (int) $service->id,
            staffId: (int) $staff->user_id,
            boothId: null,
            startsAt: CarbonImmutable::parse('2026-10-01 10:00:00'),
            source: ReservationSource::ArkWeb,
            actorUserId: (int) $customer->user_id,
            notes: null,
            adminContext: false,
            paymentMethod: $method,
        );
    }

    private function grantTicket(Customer $customer): TicketWallet
    {
        $product = TicketProduct::factory()->create(['total_count' => 5]);

        $wallet = TicketWallet::factory()->create([
            'customer_id' => $customer->user_id,
            'ticket_product_id' => $product->id,
            'purchased_count' => 5,
            'balance' => 0,
            'expires_at' => CarbonImmutable::parse('2027-01-01'),
        ]);

        // 残数の正本は台帳。GRANT を追記して available を 5 にする。
        app(TicketLedgerService::class)->append(
            wallet: $wallet,
            type: TicketTransactionType::Grant,
            delta: 5,
            dedupeKey: "grant:{$wallet->id}:test",
            reason: 'テスト付与',
        );

        return $wallet->refresh();
    }
}
