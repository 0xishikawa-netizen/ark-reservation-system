<?php

declare(strict_types=1);

namespace App\Jobs\Integration;

use App\Domain\Integration\Dto\ExternalReservationData;
use App\Domain\Integration\Enum\ErrorCategory;
use App\Domain\Integration\Enum\SyncDirection;
use App\Domain\Integration\Enum\SyncEventStatus;
use App\Domain\Integration\Enum\SyncOperation;
use App\Domain\Integration\Service\InboundReservationSync;
use App\Domain\Integration\Service\SyncEventRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * 外部予約 1 件を ARK へ取り込む。1 external = 1 job（1 件失敗で全 retry にしない・F-10）。
 * 同一 (provider, externalId, snapshot) の再 dispatch は ShouldBeUnique で 1 回に収束する。
 */
final class ProcessExternalReservationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 900;

    public function __construct(public readonly ExternalReservationData $data) {}

    public function uniqueId(): string
    {
        return 'inbound:'.hash('sha256', implode('|', [
            $this->data->provider,
            $this->data->externalReservationId,
            $this->data->startsAt->utc()->format('YmdHis'),
            (string) $this->data->serviceRef,
            (string) $this->data->staffRef,
            $this->data->externalStatus,
            $this->data->isCanceled ? '1' : '0',
            (string) ($this->data->externalUpdatedAt?->utc()->format('YmdHis') ?? ''),
            (string) $this->data->rawVersion,
        ]));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(InboundReservationSync $sync): void
    {
        // 業務判定はすべて apply() 内で conflict / skipped に収束する。
        // ここまで例外が来るのはインフラ障害（DB 切断等）のみ想定 → tries の範囲で retry。
        $sync->apply($this->data);
    }

    public function failed(Throwable $exception): void
    {
        app(SyncEventRecorder::class)->record(
            provider: $this->data->provider,
            direction: SyncDirection::Inbound,
            operation: SyncOperation::Fetch,
            status: SyncEventStatus::Failed,
            externalId: $this->data->externalReservationId,
            errorCategory: ErrorCategory::Retryable,
            safeErrorCode: class_basename($exception),
        );
    }
}
