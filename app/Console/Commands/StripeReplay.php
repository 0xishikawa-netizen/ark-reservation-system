<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Payment\Gateway\StripeGateway;
use App\Domain\Payment\Webhook\StripeWebhookProcessor;
use App\Exceptions\Payment\PaymentGatewayException;
use App\Models\WebhookEvent;
use Illuminate\Console\Command;

/**
 * 取りこぼした Stripe イベントを再処理する（PLAN §9 A）。
 *
 * Stripe 側の event 保持期間（約 30 日）に依存する短期手段。
 * **長期の整合性回復は `payments:reconcile` が本命**（現在オブジェクトから突合するため無期限）。
 */
class StripeReplay extends Command
{
    protected $signature = 'stripe:replay {event_id : Stripe の event ID（evt_...）}
        {--force : 処理済みでも再実行する}';

    protected $description = 'Stripe イベントを取得して webhook と同じ冪等経路で再処理する';

    public function handle(StripeGateway $gateway, StripeWebhookProcessor $processor): int
    {
        $eventId = (string) $this->argument('event_id');

        $existing = WebhookEvent::query()->where('stripe_event_id', $eventId)->first();

        if ($existing !== null && ! $this->option('force')) {
            $this->warn("イベント {$eventId} は既に受信済みです（status={$existing->status->value}）。");
            $this->line('再処理するには --force を指定してください。');

            return self::SUCCESS;
        }

        try {
            $event = $gateway->retrieveEvent($eventId);
        } catch (PaymentGatewayException $exception) {
            $this->error('Stripe からイベントを取得できませんでした: '.$exception::class);

            return self::FAILURE;
        }

        $payload = $this->toArray($event);

        if ($payload === null) {
            $this->error('イベントの形式を解釈できませんでした。');

            return self::FAILURE;
        }

        if ($this->option('force') && $existing !== null) {
            // 冪等ガードを外して同じ経路へ流す。
            $existing->delete();
        }

        $result = $processor->process($payload);

        $this->info("再処理結果: {$result}");

        return $result === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string, mixed>|null */
    private function toArray(mixed $event): ?array
    {
        if (is_array($event)) {
            return $event;
        }

        if (is_object($event) && method_exists($event, 'toArray')) {
            /** @var array<string, mixed> $array */
            $array = $event->toArray();

            return $array;
        }

        return null;
    }
}
