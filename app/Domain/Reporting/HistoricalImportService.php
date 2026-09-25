<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Models\Customer;
use App\Support\Security\PiiHasher;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use RuntimeException;
use ZipArchive;

/** 原本を変更せず、出典付きstagingから承認可能な過去集計値だけを登録する。 */
final class HistoricalImportService
{
    public const MAX_BYTES = 5_242_880;

    public const MAX_ROWS = 5000;

    public const MAX_COLUMNS = 50;

    private const METRICS = [
        'payment_date_revenue', 'treatment_date_revenue', 'visit_count', 'long_visit_count',
        'future_reservation_count', 'first_visit_count', 'first_visit_reservation_count',
        'new_customers', 'returning_customers', 'churn_customers', 'reached_2', 'reached_6',
        'reached_10', 'legacy_treatment_count',
    ];

    /** @return array<string,mixed> */
    public function preview(UploadedFile $file): array
    {
        $parsed = $this->parse($file);

        return $this->summarize($parsed, null);
    }

    /** @return array<string,mixed> */
    public function stage(UploadedFile $file, int $actorId): array
    {
        $parsed = $this->parse($file);
        $sha = hash_file('sha256', $file->getRealPath());
        if ($sha === false) {
            throw new RuntimeException('取込ファイルのhashを計算できません。');
        }
        $existing = DB::table('historical_import_batches')->where('source_sha256', $sha)->first();
        if ($existing !== null) {
            return $this->report((int) $existing->id);
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        $path = 'historical-imports/'.$sha.'.'.$extension;
        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false) {
            throw new RuntimeException('取込ファイルを読み取れません。');
        }
        try {
            if (! Storage::disk('local')->put($path, $stream)) {
                throw new RuntimeException('原本の保護コピーを保存できません。');
            }
        } finally {
            fclose($stream);
        }

        try {
            $batchId = DB::transaction(function () use ($file, $sha, $path, $extension, $actorId, $parsed): int {
                $now = now();
                $batchId = DB::table('historical_import_batches')->insertGetId([
                    'created_by' => $actorId,
                    'source_filename' => basename($file->getClientOriginalName()),
                    'source_disk' => 'local', 'source_path' => $path,
                    'source_sha256' => $sha, 'source_format' => $extension,
                    'status' => $parsed['status'], 'row_count' => count($parsed['rows']),
                    'error_count' => $parsed['error_count'], 'validated_at' => $now,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                foreach ($parsed['rows'] as $row) {
                    $sourceId = $row['values']['source_identifier'] ?? null;
                    $rowId = DB::table('historical_import_rows')->insertGetId([
                        'batch_id' => $batchId, 'sheet_name' => $row['sheet'], 'source_row' => $row['number'],
                        'row_hmac' => $this->hmac(json_encode($row['cells'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                        'source_identifier_ciphertext' => $sourceId === null || $sourceId === '' ? null : Crypt::encryptString($sourceId),
                        'source_identifier_hmac' => $sourceId === null || $sourceId === '' ? null : $this->hmac($sourceId),
                        'record_type' => $row['type'], 'validation_status' => $row['status'],
                        'validation_errors' => json_encode($row['errors'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        'matched_customer_id' => $row['customer_id'], 'match_method' => $row['match_method'],
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $cells = [];
                    foreach ($row['cells'] as $coordinate => $value) {
                        $cells[] = [
                            'row_id' => $rowId, 'cell_coordinate' => $coordinate,
                            'value_ciphertext' => $value === '' ? null : Crypt::encryptString($value),
                            'value_hmac' => $this->hmac($value),
                        ];
                    }
                    if ($cells !== []) {
                        DB::table('historical_import_cells')->insert($cells);
                    }
                }

                return $batchId;
            });
        } catch (\Throwable $e) {
            // 保護コピーだけを片付ける。元ファイルは絶対に操作しない。
            if (DB::table('historical_import_batches')->where('source_sha256', $sha)->doesntExist()) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        return $this->report($batchId);
    }

    /** @return array<string,mixed> */
    public function report(int $batchId): array
    {
        $batch = DB::table('historical_import_batches')->find($batchId);
        if ($batch === null) {
            throw new InvalidArgumentException('取込batchが見つかりません。');
        }
        $rows = DB::table('historical_import_rows')->where('batch_id', $batchId)
            ->orderBy('sheet_name')->orderBy('source_row')->get();

        return [
            'batch_id' => $batchId, 'source_filename' => $batch->source_filename,
            'source_sha256' => $batch->source_sha256, 'status' => $batch->status,
            'row_count' => $batch->row_count, 'error_count' => $batch->error_count,
            'imported_at' => $batch->imported_at, 'invalidated_at' => $batch->invalidated_at,
            'rows' => $rows->map(fn (object $row): array => [
                'id' => $row->id, 'sheet' => $row->sheet_name, 'row' => $row->source_row,
                'record_type' => $row->record_type, 'status' => $row->validation_status,
                'errors' => json_decode((string) $row->validation_errors, true) ?: [],
                'matched_customer_id' => $row->matched_customer_id, 'match_method' => $row->match_method,
                'reviewed_by' => $row->reviewed_by, 'reviewed_at' => $row->reviewed_at,
                'imported_at' => $row->imported_at,
            ])->all(),
        ];
    }

    /** 名前による推定は行わず、管理者が明示した既存顧客IDだけを確認記録する。 */
    public function confirmCustomerMatch(int $rowId, int $customerId, int $reviewerId): array
    {
        $batchId = DB::transaction(function () use ($rowId, $customerId, $reviewerId): int {
            $row = DB::table('historical_import_rows')->where('id', $rowId)->lockForUpdate()->first();
            if ($row === null || $row->record_type !== 'customer_detail'
                || DB::table('historical_import_batches')->where('id', $row->batch_id)->whereNotNull('invalidated_at')->exists()) {
                throw new InvalidArgumentException('確認対象の顧客行が存在しないか無効化されています。');
            }
            if (! Customer::query()->whereKey($customerId)->exists()) {
                throw new InvalidArgumentException('確認先の顧客IDが存在しません。');
            }
            DB::table('historical_import_rows')->where('id', $rowId)->update([
                'matched_customer_id' => $customerId, 'match_method' => 'manual_review',
                'reviewed_by' => $reviewerId, 'reviewed_at' => now(), 'updated_at' => now(),
            ]);

            return (int) $row->batch_id;
        });

        return $this->report($batchId);
    }

    /** 過去集計値のみを登録する。明細や顧客は推測で作らない。 @return array<string,mixed> */
    public function commit(int $batchId): array
    {
        DB::transaction(function () use ($batchId): void {
            $batch = DB::table('historical_import_batches')->where('id', $batchId)->lockForUpdate()->first();
            if ($batch === null || $batch->invalidated_at !== null) {
                throw new InvalidArgumentException('取込batchが存在しないか無効化されています。');
            }
            $rows = DB::table('historical_import_rows')->where('batch_id', $batchId)
                ->where('record_type', 'historical_aggregate')->where('validation_status', 'valid')
                ->lockForUpdate()->get();
            foreach ($rows as $row) {
                if (DB::table('historical_metric_values')->where('source_row_id', $row->id)->exists()) {
                    continue;
                }
                $values = $this->rowValues((int) $row->id);
                DB::table('historical_metric_values')->insert([
                    'batch_id' => $batchId, 'source_row_id' => $row->id,
                    'metric_code' => $values['metric_code'], 'period_start' => $values['period_start'],
                    'period_end' => $values['period_end'], 'value_integer' => (int) $values['value'],
                    'source_identifier_ciphertext' => $row->source_identifier_ciphertext,
                    'imported_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('historical_import_rows')->where('id', $row->id)
                    ->update(['validation_status' => 'imported', 'imported_at' => now(), 'updated_at' => now()]);
            }
            $pending = DB::table('historical_import_rows')->where('batch_id', $batchId)
                ->whereNotIn('validation_status', ['header', 'imported'])->exists();
            DB::table('historical_import_batches')->where('id', $batchId)->update([
                'status' => $pending ? 'partial' : 'imported', 'imported_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $this->report($batchId);
    }

    /** 登録済み集計値を削除せず無効化し、再照合可能な証跡を残す。 */
    public function invalidate(int $batchId): array
    {
        DB::transaction(function () use ($batchId): void {
            $batch = DB::table('historical_import_batches')->where('id', $batchId)->lockForUpdate()->first();
            if ($batch === null) {
                throw new InvalidArgumentException('取込batchが見つかりません。');
            }
            DB::table('historical_metric_values')->where('batch_id', $batchId)->whereNull('invalidated_at')
                ->update(['invalidated_at' => now(), 'updated_at' => now()]);
            DB::table('historical_import_batches')->where('id', $batchId)
                ->update(['status' => 'invalidated', 'invalidated_at' => now(), 'updated_at' => now()]);
        });

        return $this->report($batchId);
    }

    /** @return array<string,string> */
    private function rowValues(int $rowId): array
    {
        $row = DB::table('historical_import_rows')->find($rowId);
        $cells = DB::table('historical_import_cells')->where('row_id', $rowId)->get();
        $header = DB::table('historical_import_rows')->where('batch_id', $row->batch_id)
            ->where('sheet_name', $row->sheet_name)->where('source_row', 1)->first();
        if ($header === null) {
            throw new RuntimeException('取込ヘッダがありません。');
        }
        $headers = DB::table('historical_import_cells')->where('row_id', $header->id)->get()
            ->mapWithKeys(fn (object $cell): array => [$cell->cell_coordinate => $cell->value_ciphertext === null ? '' : Crypt::decryptString($cell->value_ciphertext)]);
        $values = [];
        foreach ($cells as $cell) {
            $coordinate = preg_replace('/\d+$/', '1', $cell->cell_coordinate);
            $name = $headers[$coordinate] ?? null;
            if ($name !== null && $name !== '') {
                $values[$name] = $cell->value_ciphertext === null ? '' : Crypt::decryptString($cell->value_ciphertext);
            }
        }

        return $values;
    }

    /** @return array<string,mixed> */
    private function parse(UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'xlsx'], true) || $file->getSize() > self::MAX_BYTES) {
            throw new InvalidArgumentException('CSV/XLSXのみ、5 MiB以下で取込できます。');
        }
        $sheets = $extension === 'csv' ? $this->readCsv($file) : $this->readXlsx($file);
        $rows = [];
        $errors = 0;
        $review = false;
        foreach ($sheets as $sheetName => $sheetRows) {
            $header = $sheetRows[1] ?? [];
            $names = [];
            foreach ($header as $coordinate => $value) {
                $names[preg_replace('/\d+$/', '', $coordinate)] = trim($value);
            }
            if (! in_array('record_type', $names, true) || count($names) !== count(array_unique(array_filter($names)))) {
                throw new InvalidArgumentException("{$sheetName}: record_type列がないか、ヘッダ名が重複しています。");
            }
            foreach ($sheetRows as $number => $cells) {
                $values = [];
                foreach ($cells as $coordinate => $value) {
                    $name = $names[preg_replace('/\d+$/', '', $coordinate)] ?? '';
                    if ($name !== '') {
                        $values[$name] = $value;
                    }
                }
                $validation = $number === 1 ? ['status' => 'header', 'errors' => [], 'type' => 'header', 'customer_id' => null, 'match_method' => null]
                    : $this->validate($values, $cells);
                if ($validation['status'] === 'invalid') {
                    $errors++;
                }
                if ($validation['status'] === 'needs_review') {
                    $review = true;
                }
                $rows[] = ['sheet' => $sheetName, 'number' => $number, 'cells' => $cells,
                    'values' => $values, ...$validation];
            }
        }
        if (count($rows) <= count($sheets)) {
            throw new InvalidArgumentException('取込対象のデータ行がありません。');
        }

        return ['rows' => $rows, 'error_count' => $errors,
            'status' => $errors > 0 ? 'invalid' : ($review ? 'needs_review' : 'validated')];
    }

    /** @param array<string,string> $values @param array<string,string> $cells @return array<string,mixed> */
    private function validate(array $values, array $cells): array
    {
        $errors = [];
        foreach ($cells as $coordinate => $value) {
            if (preg_match('/^\s*[=+@]/u', $value) === 1 || (str_starts_with(ltrim($value), '-') && ! preg_match('/^-?\d+$/', trim($value)))) {
                $errors[] = "{$coordinate}: formula_like_value";
            }
        }
        $type = $values['record_type'] ?? '';
        $customerId = null;
        $matchMethod = null;
        if ($type === 'historical_aggregate') {
            if (! in_array($values['metric_code'] ?? '', self::METRICS, true)) {
                $errors[] = 'metric_code: unsupported';
            }
            $start = $this->date($values['period_start'] ?? '');
            $end = $this->date($values['period_end'] ?? '');
            if ($start === null || $end === null || $start->gt($end)) {
                $errors[] = 'period: invalid';
            }
            if (! preg_match('/^-?\d{1,15}$/', $values['value'] ?? '')) {
                $errors[] = 'value: integer_required';
            }
        } elseif ($type === 'customer_detail') {
            [$customerId, $matchMethod] = $this->matchCustomer($values);
        } else {
            $errors[] = 'record_type: unsupported';
        }

        return ['status' => $errors !== [] ? 'invalid' : ($type === 'customer_detail' ? 'needs_review' : 'valid'),
            'errors' => $errors, 'type' => $type, 'customer_id' => $customerId, 'match_method' => $matchMethod];
    }

    /** @param array<string,string> $values @return array{?int,?string} */
    private function matchCustomer(array $values): array
    {
        if (ctype_digit($values['customer_id'] ?? '')) {
            $customer = Customer::query()->whereKey((int) $values['customer_id'])->first();
            if ($customer !== null) {
                return [(int) $customer->getKey(), 'customer_id'];
            }
        }
        if (($values['member_no'] ?? '') !== '') {
            $customer = Customer::query()->where('member_no', $values['member_no'])->first();
            if ($customer !== null) {
                return [(int) $customer->getKey(), 'member_no'];
            }
        }
        $phoneHmac = PiiHasher::phoneHmac($values['phone'] ?? null);
        if ($phoneHmac !== null) {
            $matches = Customer::query()->where('phone_hmac', $phoneHmac)->limit(2)->get();
            if ($matches->count() === 1) {
                return [(int) $matches->first()->getKey(), 'phone_hmac'];
            }
        }

        return [null, null];
    }

    private function date(string $value): ?CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Asia/Tokyo');

            return $date !== false && $date->toDateString() === $value ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,array<int,array<string,string>>> */
    private function readCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            throw new RuntimeException('CSVを読み取れません。');
        }
        $rows = [];
        try {
            while (($fields = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if (count($rows) >= self::MAX_ROWS || count($fields) > self::MAX_COLUMNS) {
                    throw new InvalidArgumentException('行数または列数が上限を超えています。');
                }
                $number = count($rows) + 1;
                $cells = [];
                foreach ($fields as $index => $field) {
                    $text = mb_convert_encoding((string) $field, 'UTF-8', ['UTF-8', 'SJIS-win']);
                    $cells[$this->column($index).$number] = $number === 1 && $index === 0 ? preg_replace('/^\xEF\xBB\xBF/', '', $text) : $text;
                }
                $rows[$number] = $cells;
            }
        } finally {
            fclose($handle);
        }

        return ['CSV' => $rows];
    }

    /** @return array<string,array<int,array<string,string>>> */
    private function readXlsx(UploadedFile $file): array
    {
        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            throw new InvalidArgumentException('XLSXファイルが不正です。');
        }
        try {
            $inflated = 0;
            if ($zip->numFiles > 500) {
                throw new InvalidArgumentException('XLSX内のファイル数が上限を超えています。');
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $inflated += (int) $zip->statIndex($index)['size'];
                if ($inflated > 50_000_000) {
                    throw new InvalidArgumentException('XLSX展開後の容量が上限を超えています。');
                }
            }
        } finally {
            $zip->close();
        }
        $reader = new Xlsx;
        $reader->setReadDataOnly(false);
        $book = $reader->load($file->getRealPath());
        $result = [];
        try {
            foreach ($book->getAllSheets() as $sheet) {
                if ($sheet->getHighestRow() > self::MAX_ROWS || Coordinate::columnIndexFromString($sheet->getHighestColumn()) > self::MAX_COLUMNS) {
                    throw new InvalidArgumentException('行数または列数が上限を超えています。');
                }
                $rows = [];
                foreach ($sheet->getRowIterator() as $row) {
                    $number = $row->getRowIndex();
                    $cells = [];
                    foreach ($row->getCellIterator() as $cell) {
                        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                            throw new InvalidArgumentException("{$sheet->getTitle()}!{$cell->getCoordinate()}: 数式セルは取り込めません。");
                        }
                        $value = $cell->getValue();
                        if ($value !== null) {
                            $cells[$cell->getCoordinate()] = (string) $value;
                        }
                    }
                    $rows[$number] = $cells;
                }
                $result[$sheet->getTitle()] = $rows;
            }
        } finally {
            $book->disconnectWorksheets();
        }

        return $result;
    }

    private function column(int $zeroBased): string
    {
        return Coordinate::stringFromColumnIndex($zeroBased + 1);
    }

    private function hmac(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    /** @param array<string,mixed> $parsed @return array<string,mixed> */
    private function summarize(array $parsed, ?int $batchId): array
    {
        return ['batch_id' => $batchId, 'status' => $parsed['status'],
            'row_count' => count($parsed['rows']), 'error_count' => $parsed['error_count'],
            'rows' => array_map(fn (array $row): array => ['sheet' => $row['sheet'], 'row' => $row['number'],
                'record_type' => $row['type'], 'status' => $row['status'], 'errors' => $row['errors'],
                'matched_customer_id' => $row['customer_id'], 'match_method' => $row['match_method']], $parsed['rows'])];
    }
}
