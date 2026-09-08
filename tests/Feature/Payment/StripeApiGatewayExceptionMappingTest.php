<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Payment\Gateway\Dto\CreatePaymentIntentCommand;
use App\Domain\Payment\Gateway\StripeApiGateway;
use App\Exceptions\Payment\PaymentGatewayDeclinedException;
use App\Exceptions\Payment\PaymentGatewayException;
use App\Exceptions\Payment\PaymentGatewayTimeoutException;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\Exception\UnknownApiErrorException;
use Stripe\StripeClient;
use Tests\TestCase;
use Throwable;

/**
 * Stripe SDK 例外 → 自前例外の対応付けの検証。
 *
 * Phase 5 で最も安全性に効く判定。
 * **「失敗を証明できないもの」を decline（確定的失敗）に分類してはいけない。**
 * 誤って decline に倒すと、Stripe 側で成立している決済を local が failed と確定し、
 * 顧客に「失敗しました」と表示したまま課金だけが残る。
 */
final class StripeApiGatewayExceptionMappingTest extends TestCase
{
    /** @return iterable<string, array{0: Throwable, 1: class-string}> */
    public static function exceptions(): iterable
    {
        // --- 曖昧（ambiguous）: failed へ確定してはいけない ---
        yield 'connection reset / read timeout' => [
            ApiConnectionException::factory('connection reset'),
            PaymentGatewayTimeoutException::class,
        ];
        yield 'DNS failure' => [
            ApiConnectionException::factory('could not resolve host'),
            PaymentGatewayTimeoutException::class,
        ];
        yield 'stripe 500' => [
            UnknownApiErrorException::factory('server error', 500),
            PaymentGatewayTimeoutException::class,
        ];
        yield 'stripe 503' => [
            UnknownApiErrorException::factory('service unavailable', 503),
            PaymentGatewayTimeoutException::class,
        ];

        // --- 確定的失敗（decline）。これだけが failed にしてよい ---
        yield 'card declined' => [
            CardException::factory('card declined', 402, null, null, null, 'card_declined', 'insufficient_funds'),
            PaymentGatewayDeclinedException::class,
        ];

        // --- 失敗は明らかだが decline ではない: failed へ確定させない ---
        yield 'invalid request 400' => [
            InvalidRequestException::factory('bad parameter', 400),
            PaymentGatewayException::class,
        ];
        yield 'authentication 401' => [
            AuthenticationException::factory('bad api key', 401),
            PaymentGatewayException::class,
        ];
        yield 'rate limit 429' => [
            RateLimitException::factory('too many requests', 429),
            PaymentGatewayException::class,
        ];
    }

    /** @param class-string $expected */
    #[DataProvider('exceptions')]
    public function test_stripe_exceptions_map_to_the_correct_severity(
        Throwable $thrown,
        string $expected,
    ): void {
        $gateway = new StripeApiGateway(new ThrowingStripeClient($thrown));

        try {
            $gateway->createPaymentIntent($this->command());
            $this->fail('例外が送出されませんでした。');
        } catch (Throwable $caught) {
            $this->assertInstanceOf($expected, $caught);

            if ($expected !== PaymentGatewayDeclinedException::class) {
                $this->assertNotInstanceOf(
                    PaymentGatewayDeclinedException::class,
                    $caught,
                    '失敗を証明できない例外を decline（確定的失敗）に分類している',
                );
            }
        }
    }

    public function test_timeout_is_never_a_decline(): void
    {
        $gateway = new StripeApiGateway(new ThrowingStripeClient(
            ApiConnectionException::factory('timeout'),
        ));

        $this->expectException(PaymentGatewayTimeoutException::class);

        $gateway->createPaymentIntent($this->command());
    }

    public function test_gateway_passes_the_supplied_idempotency_key_untouched(): void
    {
        $client = new RecordingStripeClient(ApiConnectionException::factory('stop here'));
        $gateway = new StripeApiGateway($client);

        try {
            $gateway->createPaymentIntent($this->command('pi-create:stable-operation-id'));
        } catch (PaymentGatewayTimeoutException) {
            // 期待どおり
        }

        // Gateway が key を書き換えたり自前生成したりしていないこと。
        $this->assertSame(
            'pi-create:stable-operation-id',
            $client->lastOptions['idempotency_key'] ?? null,
        );
        // manual capture が渡っていること。
        $this->assertSame('manual', $client->lastParams['capture_method'] ?? null);
    }

    public function test_gateway_never_leaks_raw_stripe_message_to_the_exception(): void
    {
        $gateway = new StripeApiGateway(new ThrowingStripeClient(
            CardException::factory(
                'Your card number is 4242424242424242 and was declined',
                402, null, null, null, 'card_declined', 'generic_decline',
            ),
        ));

        try {
            $gateway->createPaymentIntent($this->command());
            $this->fail('例外が送出されませんでした。');
        } catch (PaymentGatewayDeclinedException $exception) {
            // Stripe の生メッセージ（カード番号を含みうる）をそのまま持ち回らない。
            $this->assertStringNotContainsString('4242424242424242', $exception->getMessage());
            $this->assertSame('generic_decline', $exception->gatewayCode);
        }
    }

    private function command(string $idempotencyKey = 'pi-create:test-operation-id'): CreatePaymentIntentCommand
    {
        return new CreatePaymentIntentCommand(
            amount: 5000,
            currency: 'jpy',
            captureMethod: 'manual',
            idempotencyKey: $idempotencyKey,
        );
    }
}

/**
 * 任意の Stripe 例外を投げるテスト用クライアント。
 * Mockery では StripeClient::__get を安定して差し替えられないため実サブクラスを使う。
 */
final class ThrowingStripeClient extends StripeClient
{
    public function __construct(private readonly Throwable $error)
    {
        parent::__construct(['api_key' => 'sk_test_dummy_for_unit_test']);
    }

    public function __get($name)
    {
        return new class($this->error)
        {
            public function __construct(private readonly Throwable $error) {}

            /** @param list<mixed> $arguments */
            public function __call(string $method, array $arguments): never
            {
                throw $this->error;
            }
        };
    }
}

/**
 * 送信パラメータを記録してから例外を投げるテスト用クライアント。
 */
final class RecordingStripeClient extends StripeClient
{
    /** @var array<string, mixed> */
    public array $lastParams = [];

    /** @var array<string, mixed> */
    public array $lastOptions = [];

    public function __construct(private readonly Throwable $error)
    {
        parent::__construct(['api_key' => 'sk_test_dummy_for_unit_test']);
    }

    public function __get($name)
    {
        return new class($this, $this->error)
        {
            public function __construct(
                private readonly RecordingStripeClient $client,
                private readonly Throwable $error,
            ) {}

            /** @param list<mixed> $arguments */
            public function __call(string $method, array $arguments): never
            {
                $this->client->lastParams = is_array($arguments[0] ?? null) ? $arguments[0] : [];
                $this->client->lastOptions = is_array($arguments[1] ?? null) ? $arguments[1] : [];

                throw $this->error;
            }
        };
    }
}
