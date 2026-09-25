<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessFacts;

use App\Domain\Accounting\RevenueRecognitionService;
use App\Enums\Accounting\RevenueRecognitionContractStatus;
use App\Models\Membership;
use App\Models\MembershipReservationUsage;
use App\Models\Payment;
use App\Models\TicketReservationUsage;
use App\Models\TicketWallet;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RevenueRecognitionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_purchase_and_service_date_revenue_are_linked_without_duplicating_entitlement(): void
    {
        $wallet = TicketWallet::factory()->create();
        $payment = Payment::factory()->create(['customer_id' => $wallet->customer_id, 'amount' => 30001]);
        $usageA = TicketReservationUsage::factory()->create(['ticket_wallet_id' => $wallet->id]);
        $usageB = TicketReservationUsage::factory()->create(['ticket_wallet_id' => $wallet->id]);
        $usageC = TicketReservationUsage::factory()->create(['ticket_wallet_id' => $wallet->id]);
        $walletCount = TicketWallet::query()->count();
        $service = app(RevenueRecognitionService::class);
        $contract = $service->createTicketContract($wallet, 30001, 'ticket-contract:1', [
            'source_payment_id' => $payment->id,
            'units' => 3,
        ]);

        $first = $service->allocate($contract, 10000, '2026-09-10', 'ticket-use:1', Visit::factory()->create(), null, $usageA);
        $retry = $service->allocate($contract, 10000, '2026-09-10', 'ticket-use:1', null, null, $usageA);
        $service->allocate($contract, 10000, '2026-09-17', 'ticket-use:2', Visit::factory()->create(), null, $usageB);
        $service->allocate($contract, 10001, '2026-09-24', 'ticket-use:3', Visit::factory()->create(), null, $usageC, null, true);
        $closed = $service->close($contract);

        $this->assertSame($first->id, $retry->id);
        $this->assertSame($walletCount, TicketWallet::query()->count());
        $this->assertSame($payment->id, $contract->source_payment_id);
        $this->assertSame(30001, (int) $contract->allocations()->sum('amount'));
        $this->assertSame(RevenueRecognitionContractStatus::Closed, $closed->status);
        $this->assertDatabaseHas('revenue_allocations', ['amount' => 10001, 'is_remainder' => true]);
    }

    public function test_membership_period_contract_uses_existing_membership_and_rejects_over_allocation_atomically(): void
    {
        $membership = Membership::factory()->create();
        $usage = MembershipReservationUsage::factory()->create([
            'membership_id' => $membership->id,
            'period_start' => '2026-09-01',
        ]);
        $membershipCount = Membership::query()->count();
        $service = app(RevenueRecognitionService::class);
        $contract = $service->createMembershipContract($membership, 11000, 'membership-contract:1', [
            'period_start' => '2026-09-01', 'period_end' => '2026-10-01', 'units' => 1,
        ]);

        try {
            $service->allocate($contract, 11001, '2026-09-24', 'membership-use:too-much', null, null, null, $usage);
            $this->fail('元契約金額を超える配賦を拒否する必要があります。');
        } catch (ValidationException) {
            $this->assertDatabaseCount('revenue_allocations', 0);
            $this->assertSame($membershipCount, Membership::query()->count());
            $this->assertSame(RevenueRecognitionContractStatus::Active, $contract->fresh()->status);
        }

        $service->allocate($contract, 11000, '2026-09-24', 'membership-use:1', Visit::factory()->create(), null, null, $usage);
        $this->assertSame(RevenueRecognitionContractStatus::Closed, $service->close($contract)->status);
    }

    public function test_contract_cannot_close_when_allocations_do_not_equal_original_amount(): void
    {
        $wallet = TicketWallet::factory()->create();
        $usage = TicketReservationUsage::factory()->create(['ticket_wallet_id' => $wallet->id]);
        $service = app(RevenueRecognitionService::class);
        $contract = $service->createTicketContract($wallet, 20000, 'ticket-contract:partial');
        $service->allocate($contract, 10000, '2026-09-24', 'ticket-use:partial', Visit::factory()->create(), null, $usage);

        $this->expectException(ValidationException::class);
        $service->close($contract);
    }
}
