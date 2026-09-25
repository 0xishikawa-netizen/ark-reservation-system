<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Reporting\HistoricalImportService;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class HistoricalImportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_dry_run_does_not_persist_and_aggregate_import_is_idempotent_and_reversible(): void
    {
        $user = User::factory()->create();
        $csv = "record_type,metric_code,period_start,period_end,value,source_identifier\n"
            ."historical_aggregate,visit_count,2025-01-01,2025-01-31,12,old-jan\n"
            ."historical_aggregate,legacy_treatment_count,2025-01-01,2025-01-31,15,old-treatment\n";
        $file = UploadedFile::fake()->createWithContent('original.csv', $csv);
        $service = app(HistoricalImportService::class);
        $preview = $service->preview($file);
        $this->assertSame('validated', $preview['status']);
        $this->assertSame(0, $preview['error_count']);
        $this->assertDatabaseCount('historical_import_batches', 0);

        $staged = $service->stage($file, $user->id);
        $this->assertSame(3, $staged['row_count']);
        $batch = $staged['batch_id'];
        $this->assertSame($batch, $service->stage($file, $user->id)['batch_id']);
        $this->assertDatabaseCount('historical_import_batches', 1);
        $this->assertDatabaseCount('historical_import_cells', 18);
        $source = Storage::disk('local')->get('historical-imports/'.hash('sha256', $csv).'.csv');
        $this->assertSame($csv, $source);

        $service->commit($batch);
        $service->commit($batch);
        $this->assertDatabaseCount('historical_metric_values', 2);
        $this->assertDatabaseHas('historical_metric_values', ['metric_code' => 'visit_count', 'value_integer' => 12]);
        $service->invalidate($batch);
        $this->assertSame('invalidated', $service->report($batch)['status']);
        $this->assertDatabaseCount('historical_metric_values', 2);
        $this->assertSame(2, DB::table('historical_metric_values')->whereNotNull('invalidated_at')->count());
        $this->assertSame($csv, Storage::disk('local')->get('historical-imports/'.hash('sha256', $csv).'.csv'));
    }

    public function test_customer_name_alone_never_matches_or_generates_facts(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create(['phone' => '09012345678']);
        $csv = "record_type,name,customer_id,member_no,phone\ncustomer_detail,Same Name,,,\n"
            ."customer_detail,Other,{$customer->user_id},,\n"
            ."customer_detail,Other,,,09012345678\n";
        $report = app(HistoricalImportService::class)->stage(UploadedFile::fake()->createWithContent('customers.csv', $csv), $user->id);
        $this->assertSame('needs_review', $report['status']);
        $this->assertNull($report['rows'][1]['matched_customer_id']);
        $this->assertSame($customer->user_id, $report['rows'][2]['matched_customer_id']);
        $this->assertSame('customer_id', $report['rows'][2]['match_method']);
        $this->assertSame($customer->user_id, $report['rows'][3]['matched_customer_id']);
        $this->assertSame('phone_hmac', $report['rows'][3]['match_method']);
        $reviewed = app(HistoricalImportService::class)->confirmCustomerMatch($report['rows'][1]['id'], $customer->user_id, $user->id);
        $this->assertSame('manual_review', $reviewed['rows'][1]['match_method']);
        $this->assertSame($user->id, $reviewed['rows'][1]['reviewed_by']);
        $this->assertSame('partial', app(HistoricalImportService::class)->commit($report['batch_id'])['status']);
        $this->assertDatabaseCount('visits', 0);
        $this->assertDatabaseCount('checkouts', 0);
        $this->assertDatabaseCount('historical_metric_values', 0);
    }

    public function test_invalid_rows_report_errors_but_valid_rows_can_be_promoted_without_fabrication(): void
    {
        $user = User::factory()->create();
        $csv = "record_type,metric_code,period_start,period_end,value,source_identifier\n"
            ."historical_aggregate,visit_count,2025-02-01,2025-02-28,4,valid\n"
            ."historical_aggregate,visit_count,2025-02-01,2025-02-28,=1+1,bad\n"
            ."historical_aggregate,bogus,2025-02-01,2025-02-28,7,bad2\n";
        $service = app(HistoricalImportService::class);
        $preview = $service->preview(UploadedFile::fake()->createWithContent('mixed.csv', $csv));
        $this->assertSame('invalid', $preview['status']);
        $this->assertSame(2, $preview['error_count']);
        $staged = $service->stage(UploadedFile::fake()->createWithContent('mixed.csv', $csv), $user->id);
        $result = $service->commit($staged['batch_id']);
        $this->assertSame('partial', $result['status']);
        $this->assertDatabaseCount('historical_metric_values', 1);
        $this->assertDatabaseCount('visits', 0);
    }

    public function test_xlsx_is_staged_without_evaluating_formulas_and_shift_jis_csv_is_readable(): void
    {
        $user = User::factory()->create();
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('過去月計');
        $sheet->fromArray([
            ['record_type', 'metric_code', 'period_start', 'period_end', 'value'],
            ['historical_aggregate', 'visit_count', '2024-02-01', '2024-02-29', 7],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'ark-historical-xlsx-');
        try {
            (new Xlsx($book))->save($path);
            $upload = new UploadedFile($path, 'source.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
            $report = app(HistoricalImportService::class)->stage($upload, $user->id);
            $this->assertSame('validated', $report['status']);
            $this->assertSame('imported', app(HistoricalImportService::class)->commit($report['batch_id'])['status']);
            $this->assertDatabaseHas('historical_metric_values', ['metric_code' => 'visit_count', 'value_integer' => 7]);

            $sjis = mb_convert_encoding("record_type,metric_code,period_start,period_end,value,source_identifier\n"
                ."historical_aggregate,visit_count,2024-03-01,2024-03-31,8,旧帳票\n", 'SJIS-win', 'UTF-8');
            $this->assertSame('validated', app(HistoricalImportService::class)->preview(
                UploadedFile::fake()->createWithContent('old.csv', $sjis),
            )['status']);

            $sheet->setCellValue('E2', '=1+1');
            (new Xlsx($book))->save($path);
            $this->expectException(\InvalidArgumentException::class);
            app(HistoricalImportService::class)->preview($upload);
        } finally {
            $book->disconnectWorksheets();
            unlink($path);
        }
    }

    public function test_oversized_file_is_rejected_before_staging(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(HistoricalImportService::class)->preview(UploadedFile::fake()->create('large.csv', 6000));
    }
}
