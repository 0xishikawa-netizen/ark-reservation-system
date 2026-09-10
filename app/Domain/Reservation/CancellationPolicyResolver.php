<?php

declare(strict_types=1);

namespace App\Domain\Reservation;

use App\Support\Settings\Settings;
use JsonException;
use LogicException;

final class CancellationPolicyResolver
{
    public function __construct(private readonly Settings $settings) {}

    /** @return list<array{min_hours_before: int, refund_percent: int}> */
    public function tiers(): array
    {
        $fallback = $this->normalizedConfigTiers();

        try {
            $value = $this->settings->get('reservation.cancellation_tiers', $fallback);
        } catch (JsonException) {
            return $fallback;
        }

        if (is_string($value)) {
            try {
                $value = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return $fallback;
            }
        }

        return $this->normalizeTiers($value) ?? $fallback;
    }

    public function noShowRefundPercent(): int
    {
        $fallback = config('reservation.cancellation.no_show_refund_percent');

        if (! $this->validPercent($fallback)) {
            throw new LogicException('予約キャンセルポリシーの no-show 既定値が不正です。');
        }

        try {
            $value = $this->settings->get('reservation.no_show_refund_percent', $fallback);
        } catch (JsonException) {
            return $fallback;
        }

        return $this->validPercent($value) ? $value : $fallback;
    }

    /** @return list<array{min_hours_before: int, refund_percent: int}> */
    private function normalizedConfigTiers(): array
    {
        $tiers = $this->normalizeTiers(config('reservation.cancellation.tiers'));

        if ($tiers === null) {
            throw new LogicException('予約キャンセルポリシーの既定段階が不正です。');
        }

        return $tiers;
    }

    /** @return list<array{min_hours_before: int, refund_percent: int}>|null */
    private function normalizeTiers(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            return null;
        }

        $tiers = [];

        foreach ($value as $tier) {
            if (! is_array($tier)
                || ! array_key_exists('min_hours_before', $tier)
                || ! array_key_exists('refund_percent', $tier)
                || ! is_int($tier['min_hours_before'])
                || $tier['min_hours_before'] < 0
                || ! $this->validPercent($tier['refund_percent'])) {
                return null;
            }

            $tiers[] = [
                'min_hours_before' => $tier['min_hours_before'],
                'refund_percent' => $tier['refund_percent'],
            ];
        }

        if (! in_array(0, array_column($tiers, 'min_hours_before'), true)) {
            return null;
        }

        usort(
            $tiers,
            static fn (array $left, array $right): int => $right['min_hours_before'] <=> $left['min_hours_before'],
        );

        return $tiers;
    }

    private function validPercent(mixed $value): bool
    {
        return is_int($value) && $value >= 0 && $value <= 100;
    }
}
