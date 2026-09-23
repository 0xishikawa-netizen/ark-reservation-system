<?php

declare(strict_types=1);

namespace Tests\Feature\Membership;

use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipReservationUsage;
use App\Models\MembershipUsageTransaction;
use App\Models\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class MembershipSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_and_key_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('membership_plans', [
            'name', 'price', 'usage_count_per_period', 'billing_interval', 'stripe_price_id', 'is_active', 'sort_order',
        ]));
        $this->assertTrue(Schema::hasColumns('memberships', [
            'customer_id', 'membership_plan_id', 'stripe_subscription_id', 'membership_operation_id',
            'pending_operation', 'status', 'current_period_start', 'current_period_end', 'cancel_at_period_end',
            'grace_until', 'period_available', 'started_at', 'canceled_at', 'last_synced_at', 'needs_attention',
        ]));
        $this->assertTrue(Schema::hasColumns('membership_usage_transactions', [
            'membership_id', 'period_start', 'type', 'delta', 'reservation_id', 'staff_id', 'reason', 'dedupe_key', 'created_at',
        ]));
        $this->assertFalse(
            Schema::hasColumn('membership_usage_transactions', 'updated_at'),
            'usage ledger は追記専用のため updated_at を持たない',
        );
        $this->assertTrue(Schema::hasColumns('membership_reservation_usages', [
            'reservation_id', 'membership_id', 'period_start', 'no_show_policy', 'status', 'reserved_at', 'released_at', 'consumed_at',
        ]));
    }

    public function test_payment_operation_id_is_widened_to_64(): void
    {
        // 64 字ちょうどの operation id を持つ payment が作れる（char(36) では不可）。
        $customer = Customer::factory()->create();
        (new Payment)->forceFill([
            'customer_id' => $customer->user_id,
            'reservation_id' => null,
            'kind' => 'membership_invoice',
            'provider' => 'stripe',
            'payment_operation_id' => str_repeat('a', 64),
            'amount' => 1000,
            'currency' => 'jpy',
            'status' => 'succeeded',
            'capture_method' => 'automatic',
        ])->save();

        $this->assertDatabaseHas('payments', ['payment_operation_id' => str_repeat('a', 64)]);
    }

    public function test_usage_dedupe_key_is_unique(): void
    {
        $membership = Membership::factory()->create();
        $base = [
            'membership_id' => $membership->id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'type' => 'GRANT',
            'delta' => 4,
            'dedupe_key' => 'grant:x:2026-09-01',
            'created_at' => now(),
        ];
        MembershipUsageTransaction::query()->create($base);

        $this->expectException(QueryException::class);
        MembershipUsageTransaction::query()->create($base);
    }

    public function test_usage_ledger_rejects_update_and_delete(): void
    {
        $tx = MembershipUsageTransaction::factory()->create();

        try {
            $tx->update(['reason' => 'tampered']);
            $this->fail('update が拒否されなかった');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('追記専用', $e->getMessage());
        }

        try {
            $tx->delete();
            $this->fail('delete が拒否されなかった');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('追記専用', $e->getMessage());
        }

        $this->assertDatabaseCount('membership_usage_transactions', 1);
    }

    public function test_reservation_usage_is_one_per_reservation(): void
    {
        $usage = MembershipReservationUsage::factory()->create();

        $this->expectException(QueryException::class);
        MembershipReservationUsage::factory()->create(['reservation_id' => $usage->reservation_id]);
    }
}
