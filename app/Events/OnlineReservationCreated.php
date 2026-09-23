<?php

declare(strict_types=1);

namespace App\Events;

use App\Queries\ScheduleNotificationQuery;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * 予約台帳のオンライン予約通知（§9-12）をリアルタイム配信する。
 *
 * ShouldBroadcastNow を使い、キューワーカーの起動有無に依存せず即時配信する
 * （このアプリの QUEUE_CONNECTION=database はワーカー常駐が前提でないため）。
 * 配信に失敗しても（Reverb未起動・接続断など）例外は投げず、フロントは
 * ScheduleNotifications.vue の低頻度ポーリングで同じデータを取得できる
 * （push は「体験を速くする」ための追加であり、唯一の配信経路ではない・§11）。
 */
final class OnlineReservationCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    /** @var array{id: int, customer_name: string, service_name: string, source: string, source_label: string, starts_at: string, date: string, created_at: string} */
    public readonly array $payload;

    public function __construct(int $reservationId)
    {
        $payload = app(ScheduleNotificationQuery::class)->forReservation($reservationId);

        // オンライン予約経路でなければ空ペイロードにする（broadcastWhen で送信自体を止める）。
        $this->payload = $payload ?? [];
    }

    public function broadcastWhen(): bool
    {
        return $this->payload !== [];
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        // 台帳を見る権限を持つ管理者だけが購読できる（routes/channels.php で reservations.view を検証）。
        return [new PrivateChannel('schedule-notifications')];
    }

    public function broadcastAs(): string
    {
        return 'reservation.created';
    }

    /** @return array{id: int, customer_name: string, service_name: string, source: string, source_label: string, starts_at: string, date: string, created_at: string} */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
