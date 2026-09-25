<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessFacts;

use App\Domain\Accounting\CheckoutService;
use App\Enums\Accounting\CheckoutStatus;
use App\Models\CheckoutLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Staff;
use App\Models\TaxCategory;
use App\Models\Visit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class CheckoutServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_split_tenders_tax_snapshots_and_staff_allocations_are_finalized_exactly(): void
    {
        $visit = Visit::factory()->create();
        $tax = TaxCategory::query()->create(['code' => 'STANDARD', 'name' => '標準税率', 'is_active' => true]);
        $cash = PaymentMethod::factory()->create(['code' => 'cash', 'name' => '現金']);
        $paypay = PaymentMethod::factory()->create(['code' => 'paypay', 'name' => 'PayPay']);
        $staffA = Staff::factory()->create(['display_name' => 'A']);
        $staffB = Staff::factory()->create(['display_name' => 'B']);
        $providerPayment = Payment::factory()->create(['customer_id' => $visit->customer_id, 'amount' => 6000]);
        $service = app(CheckoutService::class);
        $checkout = $service->createDraft($visit, ['subtotal_amount' => 10000, 'tax_amount' => 1000, 'total_amount' => 11000]);
        $line = $service->addLine($checkout, [
            'item_type' => 'service', 'item_name_snapshot' => '整体', 'quantity' => 1, 'unit_amount' => 11000,
            'tax_rate_bps' => 1000, 'net_amount' => 10000, 'tax_amount' => 1000, 'gross_amount' => 11000,
            'operation_key' => 'line:1',
        ], $tax);
        $service->allocateStaff($line, $staffA, 5500, ['basis_minutes' => 30]);
        $service->allocateStaff($line, $staffB, 5500, ['basis_minutes' => 30]);
        $service->addTender($checkout, $cash, 5000, ['operation_key' => 'tender:cash']);
        $service->addTender($checkout, $paypay, 6000, ['operation_key' => 'tender:paypay'], $providerPayment);
        $finalized = $service->finalize($checkout);

        $tax->update(['name' => '変更後税区分']);
        $this->assertSame(CheckoutStatus::Finalized, $finalized->status);
        $this->assertSame('標準税率', $line->fresh()->tax_category_name_snapshot);
        $this->assertSame(11000, (int) $finalized->tenders()->sum('amount'));
        $this->assertSame($providerPayment->id, $finalized->tenders()->where('payment_method_id', $paypay->id)->value('payment_id'));
        $this->assertSame(11000, (int) $line->allocations()->sum('allocated_amount'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'checkout.finalized', 'entity_id' => (string) $checkout->id]);

        $this->expectException(RuntimeException::class);
        CheckoutLine::query()->create([
            'checkout_id' => $finalized->id, 'item_type' => 'product', 'item_name_snapshot' => '追加',
            'quantity' => 1, 'unit_amount' => 1, 'net_amount' => 1, 'tax_amount' => 0, 'gross_amount' => 1,
        ]);
    }

    public function test_total_mismatch_rolls_back_finalization_and_idempotency_keys_do_not_duplicate_rows(): void
    {
        $service = app(CheckoutService::class);
        $checkout = $service->createDraft(Visit::factory()->create(), ['subtotal_amount' => 10000, 'tax_amount' => 1000, 'total_amount' => 11000]);
        $lineData = [
            'item_type' => 'product', 'item_name_snapshot' => '物販', 'quantity' => 1, 'unit_amount' => 11000,
            'net_amount' => 10000, 'tax_amount' => 1000, 'gross_amount' => 11000,
            'is_staff_allocatable' => false, 'operation_key' => 'retry-line',
        ];
        $first = $service->addLine($checkout, $lineData);
        $second = $service->addLine($checkout, $lineData);
        $method = PaymentMethod::factory()->create();
        $service->addTender($checkout, $method, 10000, ['operation_key' => 'retry-tender']);
        $service->addTender($checkout, $method, 10000, ['operation_key' => 'retry-tender']);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('checkout_lines', 1);
        $this->assertDatabaseCount('checkout_tenders', 1);

        try {
            $service->finalize($checkout);
            $this->fail('支払不一致を拒否する必要があります。');
        } catch (ValidationException) {
            $this->assertSame(CheckoutStatus::Draft, $checkout->fresh()->status);
            $this->assertNull($checkout->fresh()->finalized_at);
        }
    }

    public function test_45_to_15_minute_staff_split_can_preserve_the_original_sales_total(): void
    {
        $service = app(CheckoutService::class);
        $checkout = $service->createDraft(Visit::factory()->create(), ['subtotal_amount' => 10000, 'tax_amount' => 1000, 'total_amount' => 11000]);
        $line = $service->addLine($checkout, [
            'item_type' => 'service', 'item_name_snapshot' => '75分施術', 'quantity' => 1, 'unit_amount' => 11000,
            'net_amount' => 10000, 'tax_amount' => 1000, 'gross_amount' => 11000,
        ]);
        $service->allocateStaff($line, Staff::factory()->create(), 8250, ['basis_minutes' => 45]);
        $service->allocateStaff($line, Staff::factory()->create(), 2750, ['basis_minutes' => 15]);

        $this->assertSame(11000, (int) $line->allocations()->sum('allocated_amount'));
        $this->assertSame(60, (int) $line->allocations()->sum('basis_minutes'));
    }

    public function test_multiple_lines_and_a_single_tender_can_be_finalized(): void
    {
        $service = app(CheckoutService::class);
        $checkout = $service->createDraft(Visit::factory()->create(), ['subtotal_amount' => 2000, 'tax_amount' => 200, 'total_amount' => 2200]);
        foreach (['施術', '商品'] as $index => $name) {
            $service->addLine($checkout, [
                'item_type' => $index === 0 ? 'service' : 'product', 'item_name_snapshot' => $name,
                'quantity' => 1, 'unit_amount' => 1100, 'net_amount' => 1000, 'tax_amount' => 100,
                'gross_amount' => 1100, 'is_staff_allocatable' => false, 'sort_order' => $index,
            ]);
        }
        $service->addTender($checkout, PaymentMethod::factory()->create(), 2200);

        $this->assertSame(CheckoutStatus::Finalized, $service->finalize($checkout)->status);
        $this->assertSame(2, $checkout->lines()->count());
        $this->assertSame(1, $checkout->tenders()->count());
    }

    public function test_database_rejects_zero_quantity_even_when_domain_service_is_bypassed(): void
    {
        $checkout = app(CheckoutService::class)->createDraft(Visit::factory()->create());

        $this->expectException(QueryException::class);
        CheckoutLine::query()->create([
            'checkout_id' => $checkout->id, 'item_type' => 'product', 'item_name_snapshot' => '不正行',
            'quantity' => 0, 'unit_amount' => 0, 'net_amount' => 0, 'tax_amount' => 0,
            'gross_amount' => 0, 'is_staff_allocatable' => false,
        ]);
    }
}
