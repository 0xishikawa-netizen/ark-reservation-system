<?php

declare(strict_types=1);

namespace App\Domain\Integration\Provider;

use App\Domain\Integration\Dto\ExternalReservationData;
use App\Domain\Integration\Exception\ProviderAmbiguousException;
use App\Domain\Integration\Exception\ProviderPermanentException;
use App\Domain\Integration\Exception\ProviderTransientException;
use Throwable;

/**
 * MockReservationProvider のふるまいを外から注入するためのインメモリストア。
 *
 * 本番コードには test-only hack を散らさない。Provider は「registry に登録された実装」であり、
 * 挙動はこのストアが空なら「予約 0 件・create は決定的な fake ref」という安全既定になる。
 * テストがシナリオを積む（fetch 結果 / 失敗注入 / 応答喪失 / 重複 / 順序逆転）。
 * container singleton として共有する。
 */
final class MockReservationStore
{
    /** @var array<string, ExternalReservationData> externalId => 現在値（reconcile 用） */
    private array $externalState = [];

    /** @var list<Throwable> 次の write 呼び出しで投げる例外（FIFO・1 回消費） */
    private array $failWriteQueue = [];

    /** @var list<Throwable> 次の fetch 呼び出しで投げる例外（FIFO・1 回消費） */
    private array $failFetchQueue = [];

    /** @var list<array{op: string, external_id: ?string, idempotency_key: string, reservation_id: int}> 実際に外部へ届いた write */
    private array $appliedWrites = [];

    /** @var array<string, string> idempotency_key => externalId（同一 key の create を 1 回に収束） */
    private array $idempotentCreates = [];

    private int $sequence = 0;

    private string $healthState = 'ok';

    // ---- テストがシナリオを積む API ----

    /**
     * 外部側に予約を存在させる（poll / reconcile の両方が現在状態として見る）。
     * pushInbound と setExternalState は同義（外部 API は通常「窓内の現在状態」を返すため）。
     */
    public function pushInbound(ExternalReservationData $data): void
    {
        $this->externalState[$data->externalReservationId] = $data;
    }

    public function setExternalState(ExternalReservationData $data): void
    {
        $this->externalState[$data->externalReservationId] = $data;
    }

    public function removeExternalState(string $externalId): void
    {
        unset($this->externalState[$externalId]);
    }

    public function failNextWrite(Throwable $exception): void
    {
        $this->failWriteQueue[] = $exception;
    }

    public function failNextFetch(Throwable $exception): void
    {
        $this->failFetchQueue[] = $exception;
    }

    public function transientNextWrite(string $message = 'mock transient'): void
    {
        $this->failNextWrite(new ProviderTransientException($message));
    }

    public function permanentNextWrite(string $message = 'mock permanent'): void
    {
        $this->failNextWrite(new ProviderPermanentException($message));
    }

    /** create/update は外部側で成功するがレスポンスだけ消失した状況。 */
    public function ambiguousNextWrite(string $message = 'mock response lost'): void
    {
        $this->failWriteQueue[] = new ProviderAmbiguousException($message);
    }

    public function setHealth(string $state): void
    {
        $this->healthState = $state;
    }

    // ---- Provider が使う内部 API ----

    public function health(): string
    {
        return $this->healthState;
    }

    /**
     * 窓内の現在の外部予約状態を返す（poll / reconcile 共通）。failNextFetch で 1 回だけ失敗注入。
     *
     * @return list<ExternalReservationData>
     */
    public function fetchCurrent(): array
    {
        $failure = array_shift($this->failFetchQueue);

        if ($failure !== null) {
            throw $failure;
        }

        return array_values($this->externalState);
    }

    public function applyCreate(string $idempotencyKey, int $reservationId): string
    {
        $failure = array_shift($this->failWriteQueue);

        if ($failure !== null && ! $failure instanceof ProviderAmbiguousException) {
            throw $failure;
        }

        if (isset($this->idempotentCreates[$idempotencyKey])) {
            $externalId = $this->idempotentCreates[$idempotencyKey];
        } else {
            $this->sequence++;
            $externalId = 'mock-ext-'.$this->sequence;
            $this->idempotentCreates[$idempotencyKey] = $externalId;
            $this->appliedWrites[] = [
                'op' => 'create', 'external_id' => $externalId,
                'idempotency_key' => $idempotencyKey, 'reservation_id' => $reservationId,
            ];
        }

        if ($failure instanceof ProviderAmbiguousException) {
            // 外部側は反映済み。レスポンスだけ消失。retry は同一 key で同じ externalId に収束。
            throw $failure;
        }

        return $externalId;
    }

    public function applyUpdate(string $externalId, string $idempotencyKey, int $reservationId): void
    {
        $this->recordWrite('update', $externalId, $idempotencyKey, $reservationId);
    }

    public function applyCancel(string $externalId, string $idempotencyKey, int $reservationId): void
    {
        $this->recordWrite('cancel', $externalId, $idempotencyKey, $reservationId);
    }

    private function recordWrite(string $op, string $externalId, string $idempotencyKey, int $reservationId): void
    {
        $failure = array_shift($this->failWriteQueue);

        if ($failure !== null && ! $failure instanceof ProviderAmbiguousException) {
            throw $failure;
        }

        // update / cancel は自然冪等。同一 key の二重適用も 1 回として数える。
        $already = array_filter(
            $this->appliedWrites,
            static fn (array $w): bool => $w['op'] === $op && $w['idempotency_key'] === $idempotencyKey,
        );

        if ($already === []) {
            $this->appliedWrites[] = [
                'op' => $op, 'external_id' => $externalId,
                'idempotency_key' => $idempotencyKey, 'reservation_id' => $reservationId,
            ];
        }

        if ($failure instanceof ProviderAmbiguousException) {
            throw $failure;
        }
    }

    /**
     * @return list<array{op: string, external_id: ?string, idempotency_key: string, reservation_id: int}>
     */
    public function appliedWrites(): array
    {
        return $this->appliedWrites;
    }

    public function appliedWriteCount(?string $op = null): int
    {
        if ($op === null) {
            return count($this->appliedWrites);
        }

        return count(array_filter($this->appliedWrites, static fn (array $w): bool => $w['op'] === $op));
    }

    public function reset(): void
    {
        $this->externalState = [];
        $this->failWriteQueue = [];
        $this->failFetchQueue = [];
        $this->appliedWrites = [];
        $this->idempotentCreates = [];
        $this->sequence = 0;
        $this->healthState = 'ok';
    }
}
