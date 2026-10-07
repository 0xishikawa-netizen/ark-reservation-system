<?php

declare(strict_types=1);

namespace App\Support\System;

use App\Enums\Reservation\ReservationStatus;
use App\Support\Jobs\FailedJobsReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Backup\BackupDestination\BackupDestination;
use Throwable;

final class SystemStatusReport
{
    /** docs/tasks/phase-08.md — 実 Stripe Test Mode 結合 QA 未完了 */
    public const REAL_STRIPE_TEST_MODE_QA = 'incomplete';

    /** 実結合 QA 完了まで本番投入不可 */
    public const MEMBERSHIP_PRODUCTION_READINESS = 'not_ready';

    /**
     * @return array{
     *     stripe_mode: 'test'|'live'|'placeholder'|'missing',
     *     reservation_authority: string,
     *     external_gateway: string,
     *     failed_jobs: array{count: int, oldest_failed_at: string|null},
     *     stale_pending_reservations: int,
     *     db_size: array{latest_mb: int|null, captured_on: string|null, alert_mb: int, over_threshold: bool},
     *     reconcile: array{measured: false, note: string},
     *     last_backup: array{measured: bool, newest_at: string|null, size_bytes: int|null, age_hours: float|null, healthy: bool, note: string},
     *     real_stripe_test_mode_qa: string,
     *     membership_production_readiness: string
     * }
     */
    public function generate(): array
    {
        $latestDbSize = DB::table('db_size_snapshots')
            ->orderByDesc('captured_on')
            ->first(['total_mb', 'captured_on']);
        $latestMb = $latestDbSize === null ? null : (int) $latestDbSize->total_mb;
        $alertMb = (int) config('retention.db_size_alert_mb');
        $oldestFailedAt = DB::table('failed_jobs')->min('failed_at');

        return [
            'stripe_mode' => $this->stripeMode(),
            'reservation_authority' => (string) config('reservation.authority'),
            'external_gateway' => (string) config('reservation.gateway'),
            'failed_jobs' => [
                'count' => app(FailedJobsReader::class)->count(),
                'oldest_failed_at' => $oldestFailedAt === null ? null : (string) $oldestFailedAt,
            ],
            'stale_pending_reservations' => (int) DB::table('reservations')
                ->where('status', ReservationStatus::PendingPayment->value)
                ->where('payment_expires_at', '<', now())
                ->count(),
            'db_size' => [
                'latest_mb' => $latestMb,
                'captured_on' => $latestDbSize === null ? null : (string) $latestDbSize->captured_on,
                'alert_mb' => $alertMb,
                'over_threshold' => $latestMb !== null && $latestMb > $alertMb,
            ],
            'reconcile' => [
                'measured' => false,
                'note' => '日次バッチで実行。結果は失敗ジョブ / ログを参照。',
            ],
            'last_backup' => $this->lastBackup(),
            'real_stripe_test_mode_qa' => self::REAL_STRIPE_TEST_MODE_QA,
            'membership_production_readiness' => self::MEMBERSHIP_PRODUCTION_READINESS,
        ];
    }

    /** @return array{measured: bool, newest_at: string|null, size_bytes: int|null, age_hours: float|null, healthy: bool, note: string} */
    private function lastBackup(): array
    {
        try {
            $destination = BackupDestination::create('backups', (string) config('backup.backup.name'));
            if (! $destination->isReachable()) {
                throw $destination->connectionError() ?? new \RuntimeException('backup disk is unreachable');
            }

            $backup = $destination->newestBackup();
            if ($backup === null) {
                return [
                    'measured' => true,
                    'newest_at' => null,
                    'size_bytes' => null,
                    'age_hours' => null,
                    'healthy' => false,
                    'note' => __('messages.backup.status_none'),
                ];
            }

            $newestAt = CarbonImmutable::instance($backup->date())->setTimezone('Asia/Tokyo');
            $now = CarbonImmutable::now('Asia/Tokyo');
            $ageHours = round(max(0, $newestAt->diffInSeconds($now, false)) / 3600, 1);
            $healthy = $newestAt->gte($now->subDay());

            return [
                'measured' => true,
                'newest_at' => $newestAt->format('Y-m-d H:i:s').' JST',
                'size_bytes' => (int) $backup->sizeInBytes(),
                'age_hours' => $ageHours,
                'healthy' => $healthy,
                'note' => __($healthy ? 'messages.backup.status_fresh' : 'messages.backup.status_stale'),
            ];
        } catch (Throwable) {
            return [
                'measured' => false,
                'newest_at' => null,
                'size_bytes' => null,
                'age_hours' => null,
                'healthy' => false,
                'note' => __('messages.backup.status_error'),
            ];
        }
    }

    private function stripeMode(): string
    {
        $credentials = [config('stripe.secret'), config('stripe.key')];

        if (collect($credentials)->contains(
            static fn (mixed $credential): bool => ! is_string($credential) || $credential === '',
        )) {
            return 'missing';
        }

        /** @var list<string> $credentials */
        if (collect($credentials)->contains(
            static fn (string $credential): bool => str_contains($credential, '_xxx'),
        )) {
            return 'placeholder';
        }

        if (collect($credentials)->contains(
            static fn (string $credential): bool => Str::startsWith($credential, ['sk_live_', 'rk_live_', 'pk_live_']),
        )) {
            return 'live';
        }

        if (collect($credentials)->every(
            static fn (string $credential): bool => Str::startsWith($credential, ['sk_test_', 'rk_test_', 'pk_test_']),
        )) {
            return 'test';
        }

        return 'placeholder';
    }
}
