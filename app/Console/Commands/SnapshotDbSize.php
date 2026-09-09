<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DbSizeSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 現在の DB 合計サイズ（MB）を日次で記録する（PLAN §14 / Phase 8）。
 *
 * - 冪等: captured_on（今日）で updateOrCreate。同日二重実行で 1 行。
 * - config('retention.db_size_alert_mb') 超過時は warning ログ + 非 zero exit
 *   （*:reconcile と同じ様式）。新しい通知チャネルは追加しない。
 * - 業務データではなく運用メトリクス。
 */
final class SnapshotDbSize extends Command
{
    /** @var string */
    protected $signature = 'db:snapshot-size';

    /** @var string */
    protected $description = '現在の DB 合計サイズ（MB）を db_size_snapshots に記録する';

    public function handle(): int
    {
        $bytes = (int) DB::table('information_schema.tables')
            ->where('table_schema', DB::getDatabaseName())
            ->sum(DB::raw('data_length + index_length'));

        // 端数は切り上げ（0 バイトでも 0 MB として記録する）。
        $totalMb = (int) ceil($bytes / 1024 / 1024);

        DbSizeSnapshot::query()->updateOrCreate(
            ['captured_on' => today()->toDateString()],
            ['total_mb' => $totalMb],
        );

        $alertMb = (int) config('retention.db_size_alert_mb');

        if ($alertMb > 0 && $totalMb > $alertMb) {
            $this->warn(sprintf('DB 使用量 %d MB が閾値 %d MB を超過しています。', $totalMb, $alertMb));

            return self::FAILURE;
        }

        $this->info(sprintf('DB 使用量: %d MB（閾値 %d MB）', $totalMb, $alertMb));

        return self::SUCCESS;
    }
}
