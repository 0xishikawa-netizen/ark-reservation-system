<?php

declare(strict_types=1);

namespace App\Domain\Payment\Webhook;

use App\Domain\Membership\Webhook\MembershipWebhookHandler;
use App\Domain\Payment\ReservationCheckoutSaga;
use App\Enums\Payment\WebhookEventStatus;
use App\Models\Payment;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stripe webhook の冪等・順序非依存な処理（PLAN §9 / phase-05 §5）。
 *
 * 設計の要:
 * - **イベントの中身で状態を決めない。** どの payment に関係するかだけを特定し、
 *   実際の更新は Stripe から現在オブジェクトを retrieve する経路（Saga::syncAndAdvance）に委ねる。
 *   これにより到着順が入れ替わっても状態が巻き戻らない。
 * - `webhook_events.stripe_event_id` の UNIQUE 制約で再送を 1 回に収束させる。
 * - payload 全文は保存しない。PII / カード情報 / client_secret はログにも残さない。
 */
final class StripeWebhookProcessor
{
    /** Phase 5: PaymentIntent / Refund 系。invoice.* / customer.subscription.* は Phase 6（MembershipWebhookHandler）。 */
    private const PAYMENT_HANDLED = [
        'payment_intent.succeeded',
        'payment_intent.amount_capturable_updated',
        'payment_intent.payment_failed',
        'payment_intent.canceled',
        'charge.refunded',
    ];

    public function __construct(
        private readonly ReservationCheckoutSaga $saga,
        private readonly MembershipWebhookHandler $membership,
    ) {}

    /**
     * @param  array<string, mixed>  $event
     * @return 'processed'|'duplicate'|'ignored'|'failed'
     */
    public function process(array $event): string
    {
        $eventId = is_string($event['id'] ?? null) ? $event['id'] : '';
        $type = is_string($event['type'] ?? null) ? $event['type'] : '';

        if ($eventId === '' || $type === '') {
            return 'ignored';
        }

        $record = $this->recordArrival($event, $eventId, $type);

        if ($record === null) {
            // 既に終了状態（processed / ignored）で記録済み＝真の再送。二重処理しない。
            return 'duplicate';
        }

        if ($this->membership->handles($type)) {
            return $this->processMembership($record, $eventId, $type, $event);
        }

        if (! in_array($type, self::PAYMENT_HANDLED, true)) {
            $this->finish($record, WebhookEventStatus::Ignored);

            return 'ignored';
        }

        $paymentIntentId = $this->paymentIntentIdFrom($event);

        if ($paymentIntentId === null) {
            $this->finish($record, WebhookEventStatus::Ignored, __('messages.webhook.payment_intent_unidentified'));

            return 'ignored';
        }

        $payment = Payment::query()
            ->where('stripe_payment_intent_id', $paymentIntentId)
            ->first();

        if ($payment === null) {
            // 当システムが作っていない PaymentIntent。無視して良いが記録は残す。
            $this->finish($record, WebhookEventStatus::Ignored, __('messages.webhook.payment_not_found'));

            return 'ignored';
        }

        $record->forceFill([
            'related_type' => 'payment',
            'related_id' => (string) $payment->getKey(),
        ])->save();

        try {
            // Stripe の現在状態を取り直して前進のみ適用する（到着順に依存しない）。
            $this->saga->syncAndAdvance($payment);
        } catch (Throwable $exception) {
            $this->finish($record, WebhookEventStatus::Failed, $exception::class);

            Log::warning('stripe webhook processing failed', [
                'event_id' => $eventId,
                'type' => $type,
                'payment_id' => $payment->getKey(),
                'reason' => $exception::class,
            ]);

            return 'failed';
        }

        $this->finish($record, WebhookEventStatus::Processed);

        return 'processed';
    }

    /**
     * invoice.* / customer.subscription.* を Membership へ反映する。
     * 冪等は webhook_events.stripe_event_id UNIQUE、順序耐性は「現在オブジェクト retrieve + 前進のみ」。
     *
     * @param  array<string, mixed>  $event
     * @return 'processed'|'ignored'|'failed'
     */
    private function processMembership(WebhookEvent $record, string $eventId, string $type, array $event): string
    {
        try {
            $outcome = $this->membership->handle($type, $event);
        } catch (Throwable $exception) {
            $this->finish($record, WebhookEventStatus::Failed, $exception::class);

            Log::warning('stripe membership webhook processing failed', [
                'event_id' => $eventId,
                'type' => $type,
                'reason' => $exception::class,
            ]);

            return 'failed';
        }

        if ($outcome['membership_id'] !== null) {
            $record->forceFill([
                'related_type' => 'membership',
                'related_id' => (string) $outcome['membership_id'],
            ])->save();
        }

        if ($outcome['result'] === 'ignored') {
            $this->finish($record, WebhookEventStatus::Ignored, $outcome['note']);

            return 'ignored';
        }

        $this->finish($record, WebhookEventStatus::Processed);

        return 'processed';
    }

    /**
     * 到着を記録する。
     *
     * - 初回: 新しい WebhookEvent 行を返す。
     * - 再送で既存行が終了状態（processed / ignored）: null（真の重複・再処理しない）。
     * - 再送で既存行が未終了（received / failed）: attempts++ して行を received に戻して返す。
     *   初回処理が一時例外やプロセス停止で未完了のまま Stripe が retry したケースを取りこぼさない。
     *   実際の反映処理（syncAndAdvance / membership handle / invoice 記録 / GRANT）はすべて冪等。
     *
     * @param  array<string, mixed>  $event
     */
    private function recordArrival(array $event, string $eventId, string $type): ?WebhookEvent
    {
        try {
            return DB::transaction(function () use ($event, $eventId, $type): WebhookEvent {
                $record = new WebhookEvent([
                    'stripe_event_id' => $eventId,
                    'type' => $type,
                    'api_version' => is_string($event['api_version'] ?? null)
                        ? substr($event['api_version'], 0, 20)
                        : null,
                    // 診断専用。処理の分岐には使わない。
                    'event_created_at' => is_int($event['created'] ?? null)
                        ? CarbonImmutable::createFromTimestamp($event['created'])
                        : null,
                    'received_at' => now(),
                ]);
                $record->status = WebhookEventStatus::Received;
                $record->attempts = 1;
                $record->save();

                return $record;
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            $existing = WebhookEvent::query()->where('stripe_event_id', $eventId)->first();

            if ($existing === null) {
                return null;
            }

            $existing->forceFill(['attempts' => $existing->attempts + 1]);

            if (in_array($existing->status, [WebhookEventStatus::Processed, WebhookEventStatus::Ignored], true)) {
                $existing->save();

                return null;
            }

            // 未完了のまま届いた再送。再処理させる。
            $existing->forceFill(['status' => WebhookEventStatus::Received, 'error' => null])->save();

            return $existing;
        }
    }

    private function finish(WebhookEvent $record, WebhookEventStatus $status, ?string $error = null): void
    {
        $record->forceFill([
            'status' => $status,
            'processed_at' => now(),
            'error' => $error === null ? null : substr($error, 0, 500),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function paymentIntentIdFrom(array $event): ?string
    {
        $object = $event['data']['object'] ?? null;

        if (! is_array($object)) {
            return null;
        }

        // payment_intent.* は object 自身、charge.* は payment_intent フィールド。
        $candidate = match (true) {
            is_string($object['object'] ?? null) && $object['object'] === 'payment_intent' => $object['id'] ?? null,
            default => $object['payment_intent'] ?? null,
        };

        return is_string($candidate) && $candidate !== '' ? $candidate : null;
    }
}
