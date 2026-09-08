<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Domain\Membership\Gateway\Dto\SubscriptionResult;
use App\Domain\Membership\Gateway\FakeMembershipStripeGateway;
use App\Domain\Membership\MembershipLedgerService;
use App\Enums\Membership\MembershipNoShowPolicy;
use App\Enums\Membership\MembershipReservationUsageStatus;
use App\Enums\Membership\MembershipStatus;
use App\Enums\Membership\MembershipUsageType;
use App\Enums\Payment\PaymentKind;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipReservationUsage;
use App\Models\MembershipUsageTransaction;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ReconcileMembershipsTest extends TestCase
{
    // Fake gateway の「Stripe は DB transaction 外」検査を実効化する。
    use DatabaseMigrations;

    private MembershipLedgerService $ledger;

    private FakeMembershipStripeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 12:00:00');
        $this->ledger = app(MembershipLedgerService::class);
        $this->gateway = app(FakeMembershipStripeGateway::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_consistent_membership_exits_successfully(): void
    {
        $this->consistentMembership('consistent');

        $this->artisan('memberships:reconcile')
            ->expectsOutputToContain('検出した差分: 0 件')
            ->assertSuccessful();

        $this->assertSame(1, $this->gateway->callCount('retrieve_subscription'));
    }

    public function test_tampered_period_available_is_reported_without_pii(): void
    {
        $user = User::factory()->create([
            'name' => '利用権照合テスト氏名',
            'email' => 'membership-reconcile-pii@example.test',
        ]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'kana' => 'リヨウケンショウゴウカナ',
        ]);
        $membership = $this->consistentMembership('tampered', customer: $customer);
        DB::table('memberships')->where('id', $membership->id)->update(['period_available' => 99]);

        $exitCode = Artisan::call('memberships:reconcile');
        $output = Artisan::output();

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString("membership#{$membership->id} customer#{$customer->user_id}", $output);
        $this->assertStringContainsString('period_available cache 不一致', $output);
        $this->assertStringNotContainsString('利用権照合テスト氏名', $output);
        $this->assertStringNotContainsString('membership-reconcile-pii@example.test', $output);
        $this->assertStringNotContainsString('リヨウケンショウゴウカナ', $output);
    }

    public function test_repair_updates_only_the_cache_and_writes_an_audit_log(): void
    {
        $membership = $this->consistentMembership('repair');
        DB::table('memberships')->where('id', $membership->id)->update([
            'period_available' => 99,
            'last_synced_at' => null,
        ]);
        $transactionCount = MembershipUsageTransaction::query()->count();

        $this->artisan('memberships:reconcile', ['--repair' => true])
            ->expectsOutputToContain('検出した差分: 0 件')
            ->assertSuccessful();

        $fresh = $membership->fresh();
        $this->assertSame($this->ledger->available($fresh), $fresh->period_available);
        $this->assertNotNull($fresh->last_synced_at);
        $this->assertSame($transactionCount, MembershipUsageTransaction::query()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'membership.reconciled',
            'entity_id' => (string) $membership->id,
        ]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'membership.reconciled')->count());
        $this->assertSame(0, $this->gatewayMutationCount());
    }

    public function test_repair_dry_run_does_not_write(): void
    {
        $membership = $this->consistentMembership('repair-dry-run');
        DB::table('memberships')->where('id', $membership->id)->update([
            'period_available' => 99,
            'last_synced_at' => null,
        ]);
        $transactionCount = MembershipUsageTransaction::query()->count();

        $this->artisan('memberships:reconcile', ['--repair' => true, '--dry-run' => true])
            ->expectsOutputToContain("membership#{$membership->id} customer#{$membership->customer_id} 修復予定")
            ->assertFailed();

        $fresh = $membership->fresh();
        $this->assertSame(99, $fresh->period_available);
        $this->assertNull($fresh->last_synced_at);
        $this->assertSame($transactionCount, MembershipUsageTransaction::query()->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'membership.reconciled']);
        $this->assertSame(0, $this->gatewayMutationCount());
    }

    public function test_duplicate_grants_in_the_current_period_are_detected(): void
    {
        $membership = $this->consistentMembership('duplicate-grant');

        DB::table('membership_usage_transactions')->insert([
            'membership_id' => $membership->id,
            'period_start' => '2026-09-01',
            'type' => MembershipUsageType::Grant->value,
            'delta' => 4,
            'reservation_id' => null,
            'staff_id' => null,
            'reason' => '異常データの再現',
            'dedupe_key' => "grant-duplicate:{$membership->id}:2026-09-01",
            'created_at' => now(),
        ]);

        $this->artisan('memberships:reconcile')
            ->expectsOutputToContain('当期 GRANT 重複')
            ->assertFailed();
    }

    public function test_reserved_usage_without_reserve_ledger_is_detected(): void
    {
        $membership = $this->consistentMembership('held-mismatch');
        $reservation = Reservation::factory()->create(['customer_id' => $membership->customer_id]);
        MembershipReservationUsage::factory()->create([
            'reservation_id' => $reservation->id,
            'membership_id' => $membership->id,
            'period_start' => '2026-09-01',
            'no_show_policy' => MembershipNoShowPolicy::Consume->value,
            'status' => MembershipReservationUsageStatus::Reserved->value,
        ]);

        $this->artisan('memberships:reconcile')
            ->expectsOutputToContain("held 不整合 membership#{$membership->id} customer#{$membership->customer_id}")
            ->assertFailed();
    }

    public function test_stale_pending_membership_is_detected_and_counted_as_needs_attention(): void
    {
        $membership = Membership::factory()->pending()->create([
            'needs_attention' => true,
            'created_at' => now()->subDays(3),
        ]);

        $this->artisan('memberships:reconcile')
            ->expectsOutputToContain("pending 滞留 membership#{$membership->id} customer#{$membership->customer_id}")
            ->expectsOutputToContain('needs_attention 未解決')
            ->expectsOutputToContain('needs_attention 1 件')
            ->assertFailed();
    }

    public function test_forward_stripe_status_drift_is_detected_and_sync_resolves_it(): void
    {
        $membership = $this->consistentMembership('forward-drift');
        $this->setStripeResult($membership, stripeStatus: 'canceled', latestInvoiceStatus: 'paid');

        $this->artisan('memberships:reconcile')
            ->expectsOutputToContain('status ドリフト（Stripe が先行）')
            ->assertFailed();

        $this->artisan('memberships:reconcile', ['--sync' => true])
            ->expectsOutputToContain('検出した差分: 0 件')
            ->assertSuccessful();

        $this->assertSame(MembershipStatus::Canceled, $membership->fresh()->status);
        $this->assertSame(0, $this->gatewayMutationCount());

        foreach ($this->gateway->calls() as $call) {
            $this->assertSame(0, $call['transaction_level']);
        }
    }

    public function test_backward_stripe_status_is_not_a_discrepancy(): void
    {
        $membership = $this->consistentMembership('backward', MembershipStatus::Canceled);
        $this->setStripeResult($membership, stripeStatus: 'active', latestInvoiceStatus: 'paid');

        $this->artisan('memberships:reconcile')
            ->expectsOutputToContain('検出した差分: 0 件')
            ->doesntExpectOutputToContain('status ドリフト')
            ->assertSuccessful();
    }

    public function test_sync_dry_run_reports_the_plan_without_writing(): void
    {
        $membership = $this->consistentMembership('sync-dry-run');
        $membership->forceFill(['last_synced_at' => now()->subDay()])->save();
        $lastSyncedAt = $membership->last_synced_at?->toDateTimeString();
        $this->setStripeResult($membership, stripeStatus: 'canceled', latestInvoiceStatus: 'paid');

        $this->artisan('memberships:reconcile', ['--sync' => true, '--dry-run' => true])
            ->expectsOutputToContain("membership#{$membership->id} customer#{$membership->customer_id} sync 予定")
            ->assertFailed();

        $fresh = $membership->fresh();
        $this->assertSame(MembershipStatus::Active, $fresh->status);
        $this->assertSame($lastSyncedAt, $fresh->last_synced_at?->toDateTimeString());
        $this->assertSame(0, $this->gatewayMutationCount());
    }

    public function test_sync_then_repair_can_be_combined(): void
    {
        $membership = $this->consistentMembership('sync-repair');
        DB::table('memberships')->where('id', $membership->id)->update(['period_available' => 99]);
        $this->setStripeResult($membership, stripeStatus: 'canceled', latestInvoiceStatus: 'paid');
        $transactionCount = MembershipUsageTransaction::query()->count();

        $this->artisan('memberships:reconcile', ['--sync' => true, '--repair' => true])
            ->expectsOutputToContain('検出した差分: 0 件')
            ->assertSuccessful();

        $fresh = $membership->fresh();
        $this->assertSame(MembershipStatus::Canceled, $fresh->status);
        $this->assertSame(4, $fresh->period_available);
        $this->assertSame($transactionCount, MembershipUsageTransaction::query()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'membership.reconciled',
            'entity_id' => (string) $membership->id,
        ]);
        $this->assertSame(0, $this->gatewayMutationCount());
    }

    public function test_membership_option_limits_inspection_to_one_membership(): void
    {
        $target = $this->consistentMembership('target');
        $other = $this->consistentMembership('other');
        DB::table('memberships')->where('id', $target->id)->update(['period_available' => 40]);
        DB::table('memberships')->where('id', $other->id)->update(['period_available' => 80]);

        $this->artisan('memberships:reconcile', ['--membership' => $target->id])
            ->expectsOutputToContain("membership#{$target->id} customer#{$target->customer_id}")
            ->doesntExpectOutputToContain("membership#{$other->id} customer#{$other->customer_id}")
            ->expectsOutputToContain('対象 membership 1 件')
            ->assertFailed();
    }

    public function test_other_local_and_stripe_invariants_are_detected(): void
    {
        $negative = Membership::factory()->create([
            'current_period_start' => '2026-09-01',
            'current_period_end' => '2026-10-01',
            'period_available' => -1,
        ]);
        DB::table('membership_usage_transactions')->insert([
            'membership_id' => $negative->id,
            'period_start' => '2026-09-01',
            'type' => MembershipUsageType::Adjust->value,
            'delta' => -1,
            'reservation_id' => null,
            'staff_id' => null,
            'reason' => '異常データの再現',
            'dedupe_key' => "negative:{$negative->id}",
            'created_at' => now(),
        ]);
        $this->setStripeResult($negative, latestInvoiceStatus: 'open');

        $grace = Membership::factory()->grace()->create([
            'grace_until' => now()->subHour(),
            'current_period_start' => '2026-09-01',
            'current_period_end' => '2026-10-01',
        ]);
        $this->setStripeResult($grace, stripeStatus: 'past_due', latestInvoiceStatus: 'open');

        $invoiceMismatch = Membership::factory()->create([
            'current_period_start' => '2026-09-01',
            'current_period_end' => '2026-10-01',
        ]);
        $this->setStripeResult(
            $invoiceMismatch,
            cancelAtPeriodEnd: true,
            periodStart: '2026-10-01',
            periodEnd: '2026-11-01',
            latestInvoiceStatus: 'paid',
        );
        Payment::factory()->create([
            'reservation_id' => null,
            'customer_id' => $invoiceMismatch->customer_id,
            'kind' => PaymentKind::MembershipInvoice->value,
            'payment_operation_id' => 'invalid-membership-invoice-key',
            'capture_method' => 'automatic',
        ]);

        $this->artisan('memberships:reconcile')
            ->expectsOutputToContain('負の available')
            ->expectsOutputToContain('grace 期限超過滞留')
            ->expectsOutputToContain('cancel_at_period_end 不一致')
            ->expectsOutputToContain('current_period_start 不一致')
            ->expectsOutputToContain('current_period_end 不一致')
            ->expectsOutputToContain('invoice 済みだが当期 GRANT 未付与')
            ->expectsOutputToContain('membership invoice payment key 違反')
            ->assertFailed();
    }

    private function consistentMembership(
        string $key,
        MembershipStatus $status = MembershipStatus::Active,
        ?Customer $customer = null,
    ): Membership {
        $membership = Membership::factory()->create([
            'customer_id' => $customer?->user_id ?? Customer::factory(),
            'status' => $status->value,
            'current_period_start' => '2026-09-01',
            'current_period_end' => '2026-10-01',
            'cancel_at_period_end' => false,
            'period_available' => 0,
            'grace_until' => null,
        ]);

        $this->ledger->grant($membership, '2026-09-01', 4, "test:{$key}");
        $this->setStripeResult($membership, stripeStatus: $status === MembershipStatus::Canceled ? 'canceled' : 'active');

        return $membership->fresh();
    }

    private function setStripeResult(
        Membership $membership,
        string $stripeStatus = 'active',
        bool $cancelAtPeriodEnd = false,
        string $periodStart = '2026-09-01',
        string $periodEnd = '2026-10-01',
        ?string $latestInvoiceStatus = 'paid',
    ): void {
        $subscriptionId = (string) $membership->stripe_subscription_id;
        $this->gateway->setSubscription($subscriptionId, new SubscriptionResult(
            stripeSubscriptionId: $subscriptionId,
            stripeStatus: $stripeStatus,
            cancelAtPeriodEnd: $cancelAtPeriodEnd,
            currentPeriodStart: $periodStart,
            currentPeriodEnd: $periodEnd,
            latestInvoiceStatus: $latestInvoiceStatus,
            latestInvoiceId: 'in_test_'.$membership->id,
        ));
    }

    private function gatewayMutationCount(): int
    {
        return $this->gateway->callCount('create_subscription')
            + $this->gateway->callCount('set_cancel_at_period_end')
            + $this->gateway->callCount('cancel_now');
    }
}
