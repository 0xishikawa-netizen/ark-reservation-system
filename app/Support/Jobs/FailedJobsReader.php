<?php

declare(strict_types=1);

namespace App\Support\Jobs;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FailedJobsReader
{
    public function count(): int
    {
        return (int) DB::table('failed_jobs')->count();
    }

    public function latest(int $perPage = 25): LengthAwarePaginator
    {
        return DB::table('failed_jobs')
            ->select(['uuid', 'connection', 'queue', 'failed_at', 'exception'])
            ->orderByDesc('failed_at')
            ->paginate($perPage)
            ->through(fn (object $job): array => [
                'uuid' => (string) $job->uuid,
                'connection' => (string) $job->connection,
                'queue' => (string) $job->queue,
                'failed_at' => (string) $job->failed_at,
                'exception_first_line' => $this->exceptionFirstLine((string) $job->exception),
            ]);
    }

    private function exceptionFirstLine(string $exception): string
    {
        $lines = preg_split('/\R/u', $exception, 2);
        $firstLine = $lines === false ? $exception : ($lines[0] ?? '');

        return Str::substr($firstLine, 0, 300);
    }
}
