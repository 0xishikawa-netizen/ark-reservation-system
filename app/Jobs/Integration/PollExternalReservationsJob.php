<?php

declare(strict_types=1);

namespace App\Jobs\Integration;

use App\Domain\Integration\Dto\FetchWindow;
use App\Domain\Integration\Enum\ProviderCapability;
use App\Domain\Integration\ProviderResolver;
use App\Models\ReservationProviderSyncState;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * 有効 Provider から直近ウィンドウの予約を fetch し、1 件ずつ ProcessExternalReservationJob を投げる。
 */
final class PollExternalReservationsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 120;

    public function uniqueId(): string
    {
        return 'poll-external-reservations';
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('poll-external-reservations'))->dontRelease()->expireAfter(300)];
    }

    public function handle(ProviderResolver $resolver): void
    {
        if (! (bool) config('reservation_integration.inbound.enabled', true) || ! $resolver->hasActive()) {
            return;
        }

        $provider = $resolver->active();

        if (! $provider->capabilities()->has(ProviderCapability::ReadReservations)) {
            return;
        }

        $windowMinutes = (int) config('reservation_integration.inbound.window_minutes', 4320);
        $now = CarbonImmutable::now();
        $window = new FetchWindow($now->subMinutes($windowMinutes), $now->addMinutes($windowMinutes));

        foreach ($provider->fetchReservations($window) as $data) {
            ProcessExternalReservationJob::dispatch($data);
        }

        ReservationProviderSyncState::query()->updateOrCreate(
            ['provider' => $provider->key()],
            ['last_inbound_at' => $now],
        );
    }
}
