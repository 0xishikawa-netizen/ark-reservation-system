<?php

declare(strict_types=1);

namespace App\Console\Commands\Integration;

use App\Domain\Integration\Service\ProviderReconciler;
use Illuminate\Console\Command;

final class ReconcileReservationProviders extends Command
{
    /** @var string */
    protected $signature = 'reservations:reconcile-providers {--provider=} {--dry-run}';

    /** @var string */
    protected $description = 'ARK と外部 Provider の予約状態を突合する（read-mostly / 差異で非 zero exit）';

    public function handle(ProviderReconciler $reconciler): int
    {
        $provider = $this->option('provider');
        $provider = is_string($provider) && $provider !== '' ? $provider : null;
        $dryRun = (bool) $this->option('dry-run');

        $report = $reconciler->reconcile($provider, $dryRun);

        $this->table(array_keys($report), [array_map('strval', $report)]);

        $flagged = $report['conflicts_opened'] + $report['drift'] + $report['stale'] + $report['ark_only'];

        if ($flagged > 0) {
            $this->warn("突合で {$flagged} 件の差分/要確認を検出しました。");

            return self::FAILURE;
        }

        $this->info('突合の差分はありません。');

        return self::SUCCESS;
    }
}
