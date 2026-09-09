<?php

declare(strict_types=1);

namespace Tests\Unit\Membership;

use App\Domain\Membership\Gateway\Dto\SubscriptionResult;
use App\Domain\Membership\Gateway\StripeApiMembershipGateway;
use App\Domain\Membership\Webhook\MembershipWebhookHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Stripe\Subscription as StripeSubscription;

/**
 * F-01: Stripe API 2026-08-26.dahlia のオブジェクト形状に追従しているかを検証する。
 * - subscription の請求期間は item 側（items.data[0].current_period_*）にある。
 * - invoice の subscription 参照は parent.subscription_details.subscription にある。
 * どちらも旧 API（トップレベル）にフォールバックできること。
 */
final class StripeApiMembershipGatewayShapeTest extends TestCase
{
    private function toResult(array $subscriptionData): SubscriptionResult
    {
        $gateway = (new ReflectionClass(StripeApiMembershipGateway::class))
            ->newInstanceWithoutConstructor();

        $method = new ReflectionMethod($gateway, 'toResult');
        $method->setAccessible(true);

        return $method->invoke($gateway, StripeSubscription::constructFrom($subscriptionData));
    }

    public function test_reads_billing_period_from_subscription_item_dahlia_shape(): void
    {
        $result = $this->toResult([
            'id' => 'sub_1',
            'status' => 'active',
            'cancel_at_period_end' => false,
            // dahlia: トップレベルに current_period_* が無い
            'items' => ['object' => 'list', 'data' => [[
                'id' => 'si_1',
                'current_period_start' => 1_756_684_800, // 2025-09-01 UTC
                'current_period_end' => 1_759_276_800,   // 2025-10-01 UTC
            ]]],
        ]);

        $this->assertSame('2025-09-01', $result->currentPeriodStart);
        $this->assertSame('2025-10-01', $result->currentPeriodEnd);
    }

    public function test_falls_back_to_top_level_billing_period_legacy_shape(): void
    {
        $result = $this->toResult([
            'id' => 'sub_2',
            'status' => 'active',
            'cancel_at_period_end' => true,
            'current_period_start' => 1_756_684_800,
            'current_period_end' => 1_759_276_800,
            'items' => ['object' => 'list', 'data' => []],
        ]);

        $this->assertSame('2025-09-01', $result->currentPeriodStart);
        $this->assertSame('2025-10-01', $result->currentPeriodEnd);
        $this->assertTrue($result->cancelAtPeriodEnd);
    }

    public function test_null_when_no_period_available_anywhere(): void
    {
        $result = $this->toResult([
            'id' => 'sub_3',
            'status' => 'incomplete',
            'cancel_at_period_end' => false,
            'items' => ['object' => 'list', 'data' => [['id' => 'si_3']]],
        ]);

        $this->assertNull($result->currentPeriodStart);
        $this->assertNull($result->currentPeriodEnd);
    }

    public function test_reads_client_secret_from_dahlia_confirmation_secret(): void
    {
        // dahlia: latest_invoice.confirmation_secret.client_secret（3DS/SCA 用）
        $result = $this->toResult([
            'id' => 'sub_4',
            'status' => 'incomplete',
            'cancel_at_period_end' => false,
            'items' => ['object' => 'list', 'data' => []],
            'latest_invoice' => [
                'object' => 'invoice',
                'id' => 'in_4',
                'status' => 'open',
                'confirmation_secret' => ['client_secret' => 'pi_dahlia_secret', 'type' => 'payment_intent'],
            ],
        ]);

        $this->assertSame('pi_dahlia_secret', $result->clientSecret);
        $this->assertTrue($result->requiresConfirmation());
    }

    public function test_reads_client_secret_from_legacy_payment_intent(): void
    {
        $result = $this->toResult([
            'id' => 'sub_5',
            'status' => 'incomplete',
            'cancel_at_period_end' => false,
            'items' => ['object' => 'list', 'data' => []],
            'latest_invoice' => [
                'object' => 'invoice',
                'id' => 'in_5',
                'status' => 'open',
                'payment_intent' => ['object' => 'payment_intent', 'id' => 'pi_5', 'client_secret' => 'pi_legacy_secret'],
            ],
        ]);

        $this->assertSame('pi_legacy_secret', $result->clientSecret);
    }

    public function test_no_confirmation_needed_when_active_and_no_secret(): void
    {
        $result = $this->toResult([
            'id' => 'sub_6',
            'status' => 'active',
            'cancel_at_period_end' => false,
            'items' => ['object' => 'list', 'data' => []],
            'latest_invoice' => ['object' => 'invoice', 'id' => 'in_6', 'status' => 'paid'],
        ]);

        $this->assertNull($result->clientSecret);
        $this->assertFalse($result->requiresConfirmation());
    }

    public function test_webhook_handler_reads_invoice_subscription_from_dahlia_parent(): void
    {
        $handler = (new ReflectionClass(MembershipWebhookHandler::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($handler, 'subscriptionIdFrom');
        $method->setAccessible(true);

        // dahlia: invoice.subscription は無く parent.subscription_details.subscription にある
        $dahlia = $method->invoke($handler, 'invoice.paid', [
            'id' => 'in_1',
            'parent' => ['subscription_details' => ['subscription' => 'sub_from_parent']],
        ]);
        $this->assertSame('sub_from_parent', $dahlia);

        // 旧 API: トップレベル invoice.subscription
        $legacy = $method->invoke($handler, 'invoice.paid', [
            'id' => 'in_2',
            'subscription' => 'sub_top_level',
        ]);
        $this->assertSame('sub_top_level', $legacy);

        // subscription.* は object.id
        $sub = $method->invoke($handler, 'customer.subscription.updated', ['id' => 'sub_direct']);
        $this->assertSame('sub_direct', $sub);
    }
}
