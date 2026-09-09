<?php

declare(strict_types=1);

namespace App\Jobs\Integration;

use App\Domain\Integration\Service\OutboxDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * pending の Outbox を batch 上限まで 1 件ずつ claim → 外部送信。
 * 個々の行の失敗は行側で pending/backoff/needs_attention へ収束するため job 自体は失敗しにくい。
 */
final class DispatchReservationOutboxJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 120;

    public function uniqueId(): string
    {
        return 'dispatch-reservation-outbox';
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('dispatch-reservation-outbox'))->dontRelease()->expireAfter(300)];
    }

    public function handle(OutboxDispatcher $dispatcher): void
    {
        $batch = (int) config('reservation_integration.outbox.batch', 25);
        $worker = 'job:'.Str::random(8);

        for ($i = 0; $i < $batch; $i++) {
            $row = $dispatcher->claimNext($worker);

            if ($row === null) {
                return;
            }

            $dispatcher->process($row);
        }
    }
}
