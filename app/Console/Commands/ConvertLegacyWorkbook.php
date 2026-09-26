<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Reporting\Import\LegacyWorkbookConverter;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * 旧帳票ブックを取込用の中間CSVへ変換する（dry-run。DBへは書かない）。Task 11-26。
 * 出力CSVは管理画面「過去データ取込」でプレビュー → stage → 手動確認 → commit する。
 */
final class ConvertLegacyWorkbook extends Command
{
    protected $signature = 'ark:legacy-import:convert
        {kind : customer_list|staff_utilization|time_band|attendance|daily_ledger|monthly_sheet}
        {path : 旧帳票xlsx（読み取り専用で開く）}
        {--output= : 中間CSVの出力先}
        {--fiscal-year= : 稼働率ブックの年度（4月始まり）}
        {--staff-label= : 出勤簿のスタッフ識別名}
        {--course-mapping= : 旧コース名→ARKコース（"type:id"）の対応JSONファイル}';

    protected $description = '旧帳票ブックを過去データ取込の中間CSVへ変換し、件数と手動確認項目を表示する（DB変更なし）';

    public function handle(LegacyWorkbookConverter $converter): int
    {
        $mapping = [];
        if (is_string($this->option('course-mapping')) && $this->option('course-mapping') !== '') {
            $decoded = json_decode((string) file_get_contents((string) $this->option('course-mapping')), true);
            if (! is_array($decoded)) {
                $this->error('コース対応JSONを読めません。');

                return self::FAILURE;
            }
            $mapping = $decoded;
        }
        try {
            $result = $converter->convert((string) $this->argument('kind'), (string) $this->argument('path'), [
                'fiscal_year' => $this->option('fiscal-year'), 'staff_label' => $this->option('staff-label'), 'course_mapping' => $mapping,
            ]);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (is_string($this->option('output')) && $this->option('output') !== '') {
            file_put_contents((string) $this->option('output'), $converter->toCsv($result['rows']));
            $this->info('中間CSV: '.$this->option('output'));
        }
        $this->table(['区分', '件数'], collect($result['counts'])->map(fn (int $count, string $key): array => [$key, $count])->values()->all());
        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }
        if ($result['manual_review'] !== []) {
            $this->line('手動確認:');
            foreach ($result['manual_review'] as $item => $count) {
                $this->line("  {$item} ({$count})");
            }
        }
        $this->line(json_encode(['legacy_reported' => $result['legacy_reported']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '');

        return self::SUCCESS;
    }
}
