<?php

declare(strict_types=1);

namespace App\Actions\Reservation;

use App\Domain\Reservation\CancellationPolicyResolver;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\Settings;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Validator;

final class UpdateReservationPolicy
{
    public function __construct(
        private readonly Settings $settings,
        private readonly CancellationPolicyResolver $resolver,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @param list<array{min_hours_before: int, refund_percent: int}> $tiers */
    public function execute(
        array $tiers,
        int $noShowRefundPercent,
        Authenticatable $actor,
    ): void {
        $validator = ValidatorFacade::make(
            [
                'tiers' => $tiers,
                'no_show_refund_percent' => $noShowRefundPercent,
            ],
            [
                'tiers' => ['required', 'array', 'min:1'],
                'tiers.*.min_hours_before' => ['required', 'integer', 'min:0', 'distinct'],
                'tiers.*.refund_percent' => ['required', 'integer', 'between:0,100'],
                'no_show_refund_percent' => ['required', 'integer', 'between:0,100'],
            ],
        );

        $validator->after(function (Validator $validator) use ($tiers): void {
            if (! collect($tiers)->contains(
                static fn (mixed $tier): bool => is_array($tier)
                    && filter_var(
                        $tier['min_hours_before'] ?? null,
                        FILTER_VALIDATE_INT,
                    ) !== false
                    && (int) $tier['min_hours_before'] === 0,
            )) {
                $validator->errors()->add(
                    'tiers',
                    __('messages.reservation.policy_zero_tier_required'),
                );
            }

            $encoded = json_encode($tiers);

            if (is_string($encoded) && strlen($encoded) > 255) {
                $validator->errors()->add(
                    'tiers',
                    __('messages.reservation.policy_too_large'),
                );
            }
        });

        $validated = $validator->validate();
        /** @var list<array{min_hours_before: int, refund_percent: int}> $normalizedTiers */
        $normalizedTiers = array_map(
            static fn (array $tier): array => [
                'min_hours_before' => (int) $tier['min_hours_before'],
                'refund_percent' => (int) $tier['refund_percent'],
            ],
            $validated['tiers'],
        );
        usort(
            $normalizedTiers,
            static fn (array $left, array $right): int => $right['min_hours_before'] <=> $left['min_hours_before'],
        );

        DB::transaction(function () use ($normalizedTiers, $noShowRefundPercent, $actor): void {
            $oldTiers = $this->resolver->tiers();
            $oldNoShowRefundPercent = $this->resolver->noShowRefundPercent();

            if ($oldTiers === $normalizedTiers && $oldNoShowRefundPercent === $noShowRefundPercent) {
                return;
            }

            $this->settings->set('reservation.cancellation_tiers', $normalizedTiers, 'json');
            $this->settings->set(
                'reservation.no_show_refund_percent',
                $noShowRefundPercent,
                'int',
            );

            $this->auditLogger->log(
                'reservation_policy.updated',
                null,
                sprintf(
                    '予約キャンセルポリシー: %s / 無断%d%% → %s / 無断%d%%',
                    $this->formatTiers($oldTiers),
                    $oldNoShowRefundPercent,
                    $this->formatTiers($normalizedTiers),
                    $noShowRefundPercent,
                ),
                $actor,
            );
        });
    }

    /** @param list<array{min_hours_before: int, refund_percent: int}> $tiers */
    private function formatTiers(array $tiers): string
    {
        return implode(', ', array_map(
            static fn (array $tier): string => "{$tier['min_hours_before']}h={$tier['refund_percent']}%",
            $tiers,
        ));
    }
}
