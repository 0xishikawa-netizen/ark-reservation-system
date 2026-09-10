<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 技術ログの保持期間 prune（Phase 9.5 hardening / PLAN §6・config/retention.php）。
 *
 * - webhook_events: `status != failed` かつ received_at が保持日数を超えたものを削除
 *   （keep_failed: 未解決の失敗は残す）。
 * - audit_logs: 金銭・回数券・利用権・PII に関わる action は無期限保持。それ以外を
 *   low_value_days 超過で削除（人手による重要操作の追跡性は損なわない）。
 *
 * どちらもバッチ削除で長時間ロックを避ける。open / failed / needs_attention は削除しない。
 */
final class PruneTechnicalLogs extends Command
{
    private const BATCH = 1000;

    /** audit_logs で無期限保持する action の部分一致トークン。 */
    private const AUDIT_KEEP_TOKENS = ['payment', 'refund', 'ticket', 'membership', 'pii'];

    /** @var string */
    protected $signature = 'system:prune-technical-logs {--dry-run}';

    /** @var string */
    protected $description = 'webhook_events / audit_logs を保持期間で prune する（open / failed / 金銭・PII は保持）';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $webhookCut = now()->subDays(
            (int) config('retention.prune.webhook_events.success_days', 90),
        );
        $auditCut = now()->subDays(
            (int) config('retention.prune.audit_logs.low_value_days', 365),
        );

        $webhookBase = fn () => DB::table('webhook_events')
            ->where('status', '!=', 'failed')
            ->where('received_at', '<', $webhookCut);

        $auditBase = function () use ($auditCut) {
            $query = DB::table('audit_logs')->where('created_at', '<', $auditCut);

            foreach (self::AUDIT_KEEP_TOKENS as $token) {
                $query->where('action', 'not like', '%'.$token.'%');
            }

            return $query;
        };

        if ($dryRun) {
            $this->info(sprintf(
                'dry-run: webhook_events %d / audit_logs %d を削除予定',
                $webhookBase()->count(),
                $auditBase()->count(),
            ));

            return self::SUCCESS;
        }

        $webhookDeleted = $this->pruneInBatches($webhookBase);
        $auditDeleted = $this->pruneInBatches($auditBase);

        $this->info(sprintf(
            '削除: webhook_events %d / audit_logs %d（基準 webhook<%s / audit<%s）',
            $webhookDeleted,
            $auditDeleted,
            $webhookCut->toDateString(),
            $auditCut->toDateString(),
        ));

        return self::SUCCESS;
    }

    /**
     * @param  \Closure(): Builder  $base
     */
    private function pruneInBatches(\Closure $base): int
    {
        $total = 0;

        do {
            $deleted = $base()->limit(self::BATCH)->delete();
            $total += $deleted;
        } while ($deleted === self::BATCH);

        return $total;
    }
}
