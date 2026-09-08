<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use Tests\TestCase;

class PaymentConfigurationTest extends TestCase
{
    public function test_stripe_configuration_uses_manual_capture_and_stable_operation_ids(): void
    {
        $this->assertSame('manual', config('stripe.capture_method'));
        $this->assertSame([
            'payment_intent_create' => 'pi-create:{payment_operation_id}',
            'payment_intent_capture' => 'pi-capture:{payment_operation_id}',
            'payment_intent_cancel' => 'pi-cancel:{payment_operation_id}',
            'refund' => 'refund:{refund_operation_id}',
        ], config('stripe.idempotency_key_templates'));
    }

    public function test_phase_five_webhook_events_are_listed(): void
    {
        $events = config('stripe.handled_events');

        foreach ([
            'payment_intent.succeeded',
            'payment_intent.amount_capturable_updated',
            'payment_intent.payment_failed',
            'payment_intent.canceled',
            'charge.refunded',
        ] as $event) {
            $this->assertContains($event, $events);
        }
    }

    public function test_http_settings_are_loaded_from_environment_configuration(): void
    {
        $this->assertSame(30, config('stripe.http.timeout'));
        $this->assertSame(10, config('stripe.http.connect_timeout'));
        $this->assertSame(2, config('stripe.http.max_network_retries'));
    }
}
