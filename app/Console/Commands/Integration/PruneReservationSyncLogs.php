<?php

declare(strict_types=1);

namespace App\Console\Commands\Integration;

use App\Models\ReservationSyncConflict;
use App\Models\ReservationSyncEvent;
use App\Models\ReservationSyncOutbox;
use Illuminate\Console\Command;

/**
 * 外部予約連携の技術ログを保持期間で prune する（Phase 9 F-17）。
 * open conflict / failed / needs_attention は保持する。
 */
final class PruneReservationSyncLogs extends Command
{
    /** @var string */
    protected $signature = 'reservations:prune-sync-logs {--dry-run}';

    /** @var string */
    protected $description = '外部予約連携の sync events / 完了 outbox / 解決済み conflict を保持期間で削除する';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = now();

        $eventsCut = $now->copy()->subDays((int) config('retention.prune.reservation_sync.events_days', 60));
        $outboxCut = $now->copy()->subDays((int) config('retention.prune.reservation_sync.outbox_days', 30));
        $conflictsCut = $now->copy()->subDays((int) config('retention.prune.reservation_sync.conflicts_days', 180));

        $events = ReservationSyncEvent::query()
            ->whereIn('status', ['succeeded', 'no_op', 'skipped'])
            ->where('created_at', '<', $eventsCut);
        $outbox = ReservationSyncOutbox::query()
            ->whereIn('status', ['succeeded', 'skipped'])
            ->whereNotNull('completed_at')
            ->where('completed_at', '<', $outboxCut);
        $conflicts = ReservationSyncConflict::query()
            ->whereIn('status', ['resolved', 'ignored'])
            ->where('updated_at', '<', $conflictsCut);

        if ($dryRun) {
            $this->info(sprintf(
                'dry-run: events %d / outbox %d / conflicts %d を削除予定',
                $events->count(), $outbox->count(), $conflicts->count(),
            ));

            return self::SUCCESS;
        }

        $e = $events->delete();
        $o = $outbox->delete();
        $c = $conflicts->delete();

        $this->info("削除: events {$e} / outbox {$o} / conflicts {$c}");

        return self::SUCCESS;
    }
}
