<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Reporting\HistoricalImportService;
use App\Http\Controllers\Controller;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class HistoricalImportController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::table('historical_import_batches')
            ->select(['id', 'source_filename', 'source_sha256', 'status', 'row_count', 'error_count',
                'created_at', 'validated_at', 'imported_at', 'invalidated_at'])
            ->orderByDesc('id')->limit(100)->get()]);
    }

    public function preview(Request $request, HistoricalImportService $imports): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120']]);

        return response()->json(['data' => $this->guard(fn (): array => $imports->preview($request->file('file')))]);
    }

    public function store(Request $request, HistoricalImportService $imports, AuditLogger $audit): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120']]);
        $report = $this->guard(fn (): array => $imports->stage($request->file('file'), (int) $request->user()->getAuthIdentifier()));
        $audit->log('historical_import.staged', null, 'batch '.$report['batch_id'], $request->user());

        return response()->json(['data' => $report], 201);
    }

    public function show(int $batch, HistoricalImportService $imports): JsonResponse
    {
        return response()->json(['data' => $this->guard(fn (): array => $imports->report($batch))]);
    }

    public function commit(int $batch, HistoricalImportService $imports, AuditLogger $audit, Request $request): JsonResponse
    {
        $report = $this->guard(fn (): array => $imports->commit($batch));
        $audit->log('historical_import.committed', null, 'batch '.$batch, $request->user());

        return response()->json(['data' => $report]);
    }

    public function confirmCustomerMatch(int $row, Request $request, HistoricalImportService $imports, AuditLogger $audit): JsonResponse
    {
        $input = $request->validate(['customer_id' => ['required', 'integer', 'min:1']]);
        $report = $this->guard(fn (): array => $imports->confirmCustomerMatch($row, (int) $input['customer_id'],
            (int) $request->user()->getAuthIdentifier()));
        $audit->log('historical_import.customer_match_reviewed', null, 'row '.$row, $request->user());

        return response()->json(['data' => $report]);
    }

    public function invalidate(int $batch, HistoricalImportService $imports, AuditLogger $audit, Request $request): JsonResponse
    {
        $report = $this->guard(fn (): array => $imports->invalidate($batch));
        $audit->log('historical_import.invalidated', null, 'batch '.$batch, $request->user());

        return response()->json(['data' => $report]);
    }

    /** @param callable():array<string,mixed> $operation @return array<string,mixed> */
    private function guard(callable $operation): array
    {
        try {
            return $operation();
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
    }
}
