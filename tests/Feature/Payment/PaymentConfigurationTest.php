<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use Tests\TestCase;

class PaymentConfigurationTest extends TestCase
{
    public function test_stripe_configuration_uses_manual_capture_and_stable_operation_ids(): void
    {
        $this->assertSame('manual', config('stripe.capture_method'));
        $templates = config('stripe.idempotency_key_templates');

        // Phase 5 の単発決済キーは不変（retry 回数・理由を混ぜない）。
        $this->assertSame('pi-create:{payment_operation_id}', $templates['payment_intent_create']);
        $this->assertSame('pi-capture:{payment_operation_id}', $templates['payment_intent_capture']);
        $this->assertSame('pi-cancel:{payment_operation_id}', $templates['payment_intent_cancel']);
        $this->assertSame('refund:{refund_operation_id}', $templates['refund']);

        // Phase 6 の subscription キーは membership_operation_id からのみ導出。
        $this->assertSame('sub-create:{membership_operation_id}', $templates['subscription_create']);
        $this->assertSame('sub-cancel:{membership_operation_id}', $templates['subscription_cancel']);
        $this->assertSame('sub-resume:{membership_operation_id}', $templates['subscription_resume']);
        $this->assertSame('sub-cancel-now:{membership_operation_id}', $templates['subscription_cancel_now']);
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
