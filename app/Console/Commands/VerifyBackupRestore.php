<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Backup\BackupDestination\BackupDestination;
use Throwable;
use ZipArchive;

final class VerifyBackupRestore extends Command
{
    /** @var string */
    protected $signature = 'ark:backup:verify-restore
        {--backup= : 検証するバックアップzipのパス（省略時は最新）}
        {--database= : 復元先DB名（省略時は env BACKUP_VERIFY_DATABASE、既定 <DB_DATABASE>_restore_check）}';

    /** @var string */
    protected $description = 'バックアップを別 DB に復元し、主要テーブルの件数を稼働 DB と比較する';

    /** @var list<string> */
    private const KEY_TABLES = [
        'reservations',
        'reservation_resource_slots',
        'payments',
        'payment_refunds',
        'customers',
        'users',
        'ticket_wallets',
        'ticket_transactions',
        'memberships',
        'membership_usage_transactions',
        'visits',
        'checkouts',
        'checkout_lines',
        'checkout_tenders',
        'audit_logs',
        'migrations',
    ];

    public function handle(): int
    {
        $mysql = (array) config('database.connections.mysql', []);
        $liveDatabase = (string) ($mysql['database'] ?? '');
        $targetDatabase = (string) ($this->option('database')
            ?: config('backup.verify_restore.database')
            ?: $liveDatabase.'_restore_check');

        if (strtolower($targetDatabase) === strtolower($liveDatabase)) {
            $this->error(__('messages.backup_restore.same_database'));

            return self::FAILURE;
        }

        if (preg_match('/\A[A-Za-z0-9_]+\z/', $targetDatabase) !== 1) {
            $this->error(__('messages.backup_restore.invalid_database'));

            return self::FAILURE;
        }

        $temporaryDirectory = sys_get_temp_dir().'/ark-backup-verify-'.Str::uuid();
        File::makeDirectory($temporaryDirectory, 0700, true);

        try {
            $archivePath = $this->copyBackupToTemporaryDirectory($temporaryDirectory);
            if ($archivePath === null) {
                return self::FAILURE;
            }

            $sqlPath = $this->extractDatabaseDump($archivePath, $temporaryDirectory);
            if ($sqlPath === null) {
                return self::FAILURE;
            }

            if (! $this->validateDumpStatements($sqlPath)) {
                return self::FAILURE;
            }

            try {
                DB::connection('mysql')->statement("CREATE DATABASE IF NOT EXISTS `{$targetDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (Throwable) {
                $this->error(__('messages.backup_restore.database_create_failed'));

                return self::FAILURE;
            }

            if (! $this->importDump($sqlPath, $targetDatabase, $mysql)) {
                return self::FAILURE;
            }

            $this->info(__('messages.backup_restore.import_succeeded', ['database' => $targetDatabase]));

            return $this->compareKeyTableCounts($targetDatabase, $mysql);
        } catch (Throwable) {
            $this->error(__('messages.backup_restore.verification_failed'));

            return self::FAILURE;
        } finally {
            DB::disconnect('backup_verify');
            DB::purge('backup_verify');
            File::deleteDirectory($temporaryDirectory);
        }
    }

    private function copyBackupToTemporaryDirectory(string $temporaryDirectory): ?string
    {
        $requested = trim((string) $this->option('backup'));
        $destination = $temporaryDirectory.'/backup.zip';

        if ($requested !== '' && is_file($requested)) {
            return copy($requested, $destination) ? $destination : $this->backupReadFailure();
        }

        $disk = Storage::disk('backups');
        $path = $requested;
        if ($path === '') {
            $backup = BackupDestination::create('backups', (string) config('backup.backup.name'))->newestBackup();
            $path = $backup?->path() ?? '';
        }

        if ($path === '' || ! $disk->exists($path)) {
            $this->error(__('messages.backup_restore.backup_not_found'));

            return null;
        }

        $source = $disk->readStream($path);
        $target = fopen($destination, 'wb');
        if ($source === false || $target === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }

            return $this->backupReadFailure();
        }

        try {
            if (stream_copy_to_stream($source, $target) === false) {
                return $this->backupReadFailure();
            }
        } finally {
            fclose($source);
            fclose($target);
        }

        return $destination;
    }

    private function backupReadFailure(): null
    {
        $this->error(__('messages.backup_restore.backup_unreadable'));

        return null;
    }

    private function extractDatabaseDump(string $archivePath, string $temporaryDirectory): ?string
    {
        $archive = new ZipArchive;
        if ($archive->open($archivePath) !== true) {
            $this->error(__('messages.backup_restore.archive_invalid'));

            return null;
        }

        try {
            $password = (string) config('backup.backup.password', '');
            if ($password !== '') {
                $archive->setPassword($password);
            }

            $dumpEntry = null;
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->getNameIndex($index);
                if ($entry === false) {
                    continue;
                }
                if (! $this->isSafeArchiveEntry($entry)) {
                    $this->error(__('messages.backup_restore.archive_unsafe'));

                    return null;
                }
                if ($dumpEntry === null && preg_match('#\Adb-dumps/[^/]+\.sql(?:\.gz)?\z#', $entry) === 1) {
                    $dumpEntry = $entry;
                }
            }

            if ($dumpEntry === null) {
                $this->error(__('messages.backup_restore.dump_not_found'));

                return null;
            }

            $archiveStream = $archive->getStream($dumpEntry);
            $dumpPath = $temporaryDirectory.'/database'.(str_ends_with($dumpEntry, '.gz') ? '.sql.gz' : '.sql');
            $dumpStream = fopen($dumpPath, 'wb');
            if ($archiveStream === false || $dumpStream === false) {
                if (is_resource($archiveStream)) {
                    fclose($archiveStream);
                }
                if (is_resource($dumpStream)) {
                    fclose($dumpStream);
                }
                $this->error(__('messages.backup_restore.dump_extract_failed'));

                return null;
            }

            try {
                if (stream_copy_to_stream($archiveStream, $dumpStream) === false) {
                    $this->error(__('messages.backup_restore.dump_extract_failed'));

                    return null;
                }
            } finally {
                fclose($archiveStream);
                fclose($dumpStream);
            }

            return str_ends_with($dumpPath, '.gz')
                ? $this->decompressDump($dumpPath, $temporaryDirectory.'/database.sql')
                : $dumpPath;
        } finally {
            $archive->close();
        }
    }

    private function isSafeArchiveEntry(string $entry): bool
    {
        if ($entry === '' || str_contains($entry, "\0") || str_contains($entry, '\\')
            || str_starts_with($entry, '/') || preg_match('/\A[A-Za-z]:/', $entry) === 1) {
            return false;
        }

        foreach (explode('/', $entry) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function decompressDump(string $gzipPath, string $sqlPath): ?string
    {
        $source = gzopen($gzipPath, 'rb');
        $target = fopen($sqlPath, 'wb');
        if ($source === false || $target === false) {
            if (is_resource($source)) {
                gzclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }
            $this->error(__('messages.backup_restore.dump_extract_failed'));

            return null;
        }

        try {
            while (! gzeof($source)) {
                $chunk = gzread($source, 1024 * 1024);
                if ($chunk === false || fwrite($target, $chunk) === false) {
                    $this->error(__('messages.backup_restore.dump_extract_failed'));

                    return null;
                }
            }
        } finally {
            gzclose($source);
            fclose($target);
        }

        return $sqlPath;
    }

    private function validateDumpStatements(string $sqlPath): bool
    {
        $stream = fopen($sqlPath, 'rb');
        if ($stream === false) {
            $this->error(__('messages.backup_restore.dump_extract_failed'));

            return false;
        }

        try {
            while (($line = fgets($stream)) !== false) {
                $startsWithUnsafeStatement = preg_match(
                    '/^\s*(USE\s|CREATE\s+DATABASE|CREATE\s+SCHEMA|DROP\s+DATABASE|DROP\s+SCHEMA)/i',
                    $line,
                ) === 1;
                $conditionalCreateDatabase = preg_match('/^\s*(?:CREATE\b|\/\*!)/i', $line) === 1
                    && preg_match('/CREATE\s+DATABASE/i', $line) === 1;

                if ($startsWithUnsafeStatement || $conditionalCreateDatabase) {
                    $this->error(__('messages.backup_restore.dump_database_statement_unsafe'));

                    return false;
                }
            }

            if (! feof($stream)) {
                $this->error(__('messages.backup_restore.dump_extract_failed'));

                return false;
            }
        } finally {
            fclose($stream);
        }

        return true;
    }

    /** @param array<string, mixed> $mysql */
    private function importDump(string $sqlPath, string $database, array $mysql): bool
    {
        $command = ['mysql'];
        $socket = (string) ($mysql['unix_socket'] ?? '');
        if ($socket !== '') {
            $command[] = '--socket='.$socket;
        } else {
            $command[] = '--host='.(string) ($mysql['host'] ?? '127.0.0.1');
            $command[] = '--port='.(string) ($mysql['port'] ?? '3306');
        }
        $command[] = '--user='.(string) ($mysql['username'] ?? '');
        $command[] = '--default-character-set='.(string) ($mysql['charset'] ?? 'utf8mb4');
        $command[] = $database;

        $input = fopen($sqlPath, 'rb');
        if ($input === false) {
            $this->error(__('messages.backup_restore.dump_extract_failed'));

            return false;
        }

        try {
            $result = Process::env(['MYSQL_PWD' => (string) ($mysql['password'] ?? '')])
                ->timeout(3600)
                ->input($input)
                ->run($command);
        } finally {
            fclose($input);
        }

        if ($result->failed()) {
            $this->error(__('messages.backup_restore.import_failed'));

            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $mysql */
    private function compareKeyTableCounts(string $database, array $mysql): int
    {
        config()->set('database.connections.backup_verify', [
            ...$mysql,
            'url' => null,
            'database' => $database,
        ]);
        DB::purge('backup_verify');

        $live = DB::connection('mysql');
        $restored = DB::connection('backup_verify');
        $rows = [];
        $failed = false;

        foreach (self::KEY_TABLES as $table) {
            if (! $restored->getSchemaBuilder()->hasTable($table)) {
                $rows[] = [$table, $this->tableCount($live, $table), __('messages.backup_restore.missing'), __('messages.backup_restore.missing')];
                $this->error(__('messages.backup_restore.restore_table_missing', ['table' => $table]));
                $failed = true;

                continue;
            }

            $liveCount = $this->tableCount($live, $table);
            $restoreCount = (int) $restored->table($table)->count();
            $matches = $liveCount === $restoreCount;
            $rows[] = [$table, $liveCount, $restoreCount, __($matches ? 'messages.backup_restore.matched' : 'messages.backup_restore.different')];

            if (! $matches && $table === 'migrations') {
                $this->error(__('messages.backup_restore.migrations_difference'));
                $failed = true;
            } elseif (! $matches) {
                $this->warn(__('messages.backup_restore.count_difference', ['table' => $table]));
            }
        }

        $this->table([
            __('messages.backup_restore.table_header'),
            __('messages.backup_restore.live_header'),
            __('messages.backup_restore.restore_header'),
            __('messages.backup_restore.result_header'),
        ], $rows);

        if ($failed) {
            return self::FAILURE;
        }

        $this->info(__('messages.backup_restore.verification_succeeded'));

        return self::SUCCESS;
    }

    private function tableCount(ConnectionInterface $connection, string $table): int|string
    {
        return $connection->getSchemaBuilder()->hasTable($table)
            ? (int) $connection->table($table)->count()
            : __('messages.backup_restore.missing');
    }
}
