<?php

declare(strict_types=1);

namespace App\Domain\Integration\Dto;

use Carbon\CarbonImmutable;

/**
 * Provider 非依存の正規化予約データ（Inbound）。
 *
 * - raw payload を DB へ丸ごと保存しない。PII は必要最小限。contact は生成時点で mask 済みを渡す。
 * - externalStatus は生文字列。ARK status への写像は ExternalStatusMapper が明示的に行う。
 * - fingerprint は「反映後の正規化 status（canonical）」を与えて計算する。ends_at は含めない
 *   （ARK は service 所要時間から再計算するため比較できない・F-08）。
 */
final readonly class ExternalReservationData
{
    public function __construct(
        public string $provider,
        public string $externalReservationId,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public string $externalStatus,
        public bool $isCanceled,
        public ?string $externalCustomerId = null,
        public ?string $serviceRef = null,
        public ?string $staffRef = null,
        public ?CarbonImmutable $externalUpdatedAt = null,
        public ?string $rawVersion = null,
    ) {}

    /**
     * provider を含まない正規化 fingerprint。starts_at / service / staff / canonical status で決まる。
     * canonical は ExternalStatusMapper 適用後の ReservationStatus 値。不明時は 'unknown'。
     */
    public function fingerprint(string $canonicalStatus = 'unknown'): string
    {
        return hash('sha256', implode('|', [
            $this->startsAt->utc()->format('YmdHis'),
            (string) $this->serviceRef,
            (string) $this->staffRef,
            $this->isCanceled ? 'canceled' : $canonicalStatus,
        ]));
    }

    /** 順序比較の材料を持つ Provider か（無い場合は既存予約の自動更新をしない・F-02）。 */
    public function hasOrderingSignal(): bool
    {
        return $this->externalUpdatedAt !== null || $this->rawVersion !== null;
    }

    public function maskedExternalId(): ?string
    {
        return ProviderRef::mask($this->externalReservationId);
    }

    /** 表示用 mask とは別の、衝突しない識別ハッシュ（F-16）。 */
    public function refHash(): string
    {
        return hash('sha256', $this->provider.'|'.$this->externalReservationId);
    }
}
