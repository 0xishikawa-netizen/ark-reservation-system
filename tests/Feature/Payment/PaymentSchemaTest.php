<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Payment\PaymentStateMachine;
use App\Enums\Payment\PaymentKind;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Payment\RefundStatus;
use App\Enums\Payment\WebhookEventStatus;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_tables_have_the_required_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('payments', [
            'id',
            'customer_id',
            'reservation_id',
            'kind',
            'provider',
            'payment_operation_id',
            'amount',
            'currency',
            'status',
            'capture_method',
            'stripe_payment_intent_id',
            'stripe_charge_id',
            'authorized_at',
            'paid_at',
            'voided_at',
            'refunded_amount',
            'failure_code',
            'failure_message',
            'needs_attention',
            'last_synced_at',
            'created_by',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('payment_refunds', [
            'id',
            'payment_id',
            'refund_operation_id',
            'amount',
            'reason',
            'status',
            'stripe_refund_id',
            'failure_code',
            'failure_message',
            'created_by',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('webhook_events', [
            'id',
            'stripe_event_id',
            'type',
            'api_version',
            'status',
            'related_type',
            'related_id',
            'event_created_at',
            'received_at',
            'processed_at',
            'attempts',
            'error',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_operation_and_stripe_ids_have_unique_indexes(): void
    {
        $this->assertUniqueIndex('payments', ['payment_operation_id']);
        $this->assertUniqueIndex('payments', ['stripe_payment_intent_id']);
        $this->assertUniqueIndex('payment_refunds', ['refund_operation_id']);
        $this->assertUniqueIndex('webhook_events', ['stripe_event_id']);
    }

    public function test_duplicate_payment_operation_id_is_rejected_by_the_database(): void
    {
        $operationId = fake()->uuid();

        Payment::factory()->create(['payment_operation_id' => $operationId]);

        $this->expectException(QueryException::class);

        Payment::factory()->create(['payment_operation_id' => $operationId]);
    }

    public function test_duplicate_stripe_payment_intent_id_is_rejected_by_the_database(): void
    {
        $paymentIntentId = 'pi_duplicate_test';

        Payment::factory()->create(['stripe_payment_intent_id' => $paymentIntentId]);

        $this->expectException(QueryException::class);

        Payment::factory()->create(['stripe_payment_intent_id' => $paymentIntentId]);
    }

    public function test_duplicate_refund_operation_id_is_rejected_by_the_database(): void
    {
        $operationId = fake()->uuid();

        PaymentRefund::factory()->create(['refund_operation_id' => $operationId]);

        $this->expectException(QueryException::class);

        PaymentRefund::factory()->create(['refund_operation_id' => $operationId]);
    }

    public function test_refund_reason_is_required_by_the_database(): void
    {
        $this->expectException(QueryException::class);

        PaymentRefund::factory()->create(['reason' => null]);
    }

    public function test_duplicate_stripe_event_id_is_rejected_by_the_database(): void
    {
        $eventId = 'evt_duplicate_test';

        WebhookEvent::factory()->create(['stripe_event_id' => $eventId]);

        $this->expectException(QueryException::class);

        WebhookEvent::factory()->create(['stripe_event_id' => $eventId]);
    }

    public function test_webhook_events_does_not_store_payload_columns(): void
    {
        foreach (['payload', 'raw_payload', 'request_body'] as $column) {
            $this->assertFalse(Schema::hasColumn('webhook_events', $column));
        }
    }

    public function test_payment_state_machine_allows_only_forward_transitions(): void
    {
        $machine = new PaymentStateMachine;
        $allowed = [
            'pending' => ['authorized', 'failed', 'voided'],
            'authorized' => ['succeeded', 'voided', 'failed'],
            'succeeded' => ['refunded', 'partially_refunded'],
            'partially_refunded' => ['refunded'],
            'voided' => [],
            'failed' => [],
            'refunded' => [],
        ];

        foreach (PaymentStatus::cases() as $from) {
            foreach (PaymentStatus::cases() as $to) {
                $this->assertSame(
                    in_array($to->value, $allowed[$from->value], true),
                    $machine->can($from->value, $to->value),
                    "{$from->value} から {$to->value} への遷移判定が仕様と異なります。",
                );
            }
        }

        $this->assertFalse($machine->can('succeeded', 'authorized'));
        $this->assertFalse($machine->can('partially_refunded', 'succeeded'));
        $this->assertFalse($machine->can('refunded', 'partially_refunded'));
    }

    public function test_payment_enum_values_match_the_database_contract(): void
    {
        $this->assertSame(
            ['pending', 'authorized', 'succeeded', 'voided', 'failed', 'refunded', 'partially_refunded'],
            array_column(PaymentStatus::cases(), 'value'),
        );
        $this->assertSame(
            ['single', 'ticket_purchase', 'membership_invoice'],
            array_column(PaymentKind::cases(), 'value'),
        );
        $this->assertSame(
            ['pending', 'succeeded', 'failed'],
            array_column(RefundStatus::cases(), 'value'),
        );
        $this->assertSame(
            ['received', 'processed', 'ignored', 'failed'],
            array_column(WebhookEventStatus::cases(), 'value'),
        );
    }

    public function test_payment_relations_are_connected(): void
    {
        $payment = Payment::factory()->create();
        $refund = PaymentRefund::factory()->create(['payment_id' => $payment->id]);

        $this->assertSame($payment->reservation_id, $payment->reservation->id);
        $this->assertTrue($payment->customer->is($payment->reservation->customer));
        $this->assertTrue($payment->refunds->contains($refund));
        $this->assertTrue($payment->reservation->payments->contains($payment));
        $this->assertTrue($payment->customer->payments->contains($payment));
    }

    public function test_statuses_cannot_be_mass_assigned(): void
    {
        $this->assertFalse((new Payment)->isFillable('status'));
        $this->assertFalse((new PaymentRefund)->isFillable('status'));
        $this->assertFalse((new WebhookEvent)->isFillable('status'));
    }

    /** @param list<string> $columns */
    private function assertUniqueIndex(string $table, array $columns): void
    {
        $hasUniqueIndex = collect(Schema::getIndexes($table))->contains(
            static fn (array $index): bool => ($index['unique'] ?? false)
                && ($index['columns'] ?? []) === $columns,
        );

        $this->assertTrue(
            $hasUniqueIndex,
            sprintf('%s.%s に UNIQUE 制約がありません。', $table, implode(',', $columns)),
        );
    }
}
