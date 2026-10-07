<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

final class VerifyBackupRestoreCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_refuses_the_live_database_as_restore_target(): void
    {
        $liveDatabase = (string) config('database.connections.mysql.database');

        $this->artisan('ark:backup:verify-restore', ['--database' => $liveDatabase])
            ->expectsOutput(__('messages.backup_restore.same_database'))
            ->assertFailed();
    }

    public function test_command_refuses_the_live_database_with_different_letter_case(): void
    {
        $liveDatabase = (string) config('database.connections.mysql.database');

        $this->artisan('ark:backup:verify-restore', ['--database' => strtoupper($liveDatabase)])
            ->expectsOutput(__('messages.backup_restore.same_database'))
            ->assertFailed();
    }

    public function test_command_refuses_an_invalid_database_identifier(): void
    {
        $this->artisan('ark:backup:verify-restore', ['--database' => 'restore-check;DROP DATABASE'])
            ->expectsOutput(__('messages.backup_restore.invalid_database'))
            ->assertFailed();
    }

    public function test_command_refuses_a_dump_that_switches_databases_before_import(): void
    {
        $backup = $this->createBackupArchive([
            'db-dumps/database.sql' => "USE `laravel`;\nCREATE TABLE example (id BIGINT);\n",
        ]);
        Process::fake();

        $this->artisan('ark:backup:verify-restore', [
            '--backup' => $backup,
            '--database' => 'restore_check',
        ])
            ->expectsOutput(__('messages.backup_restore.dump_database_statement_unsafe'))
            ->assertFailed();

        Process::assertNothingRan();
    }

    public function test_command_scans_a_gzip_compressed_dump(): void
    {
        $gzip = gzencode("/*!40000 CREATE DATABASE `laravel` */;\n");
        $this->assertIsString($gzip);
        $backup = $this->createBackupArchive([
            'db-dumps/database.sql.gz' => $gzip,
        ]);
        Process::fake();

        $this->artisan('ark:backup:verify-restore', [
            '--backup' => $backup,
            '--database' => 'restore_check',
        ])
            ->expectsOutput(__('messages.backup_restore.dump_database_statement_unsafe'))
            ->assertFailed();

        Process::assertNothingRan();
    }

    public function test_command_refuses_an_unsafe_entry_after_the_database_dump(): void
    {
        $backup = $this->createBackupArchive([
            'db-dumps/database.sql' => "SELECT 1;\n",
            '../outside.txt' => 'unsafe',
        ]);
        Process::fake();

        $this->artisan('ark:backup:verify-restore', [
            '--backup' => $backup,
            '--database' => 'restore_check',
        ])
            ->expectsOutput(__('messages.backup_restore.archive_unsafe'))
            ->assertFailed();

        Process::assertNothingRan();
    }

    /** @param array<string, string> $entries */
    private function createBackupArchive(array $entries): string
    {
        Storage::fake('backups');
        config()->set('backup.backup.password', null);

        $path = Storage::disk('backups')->path('backup.zip');
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        foreach ($entries as $name => $contents) {
            $this->assertTrue($archive->addFromString($name, $contents));
        }

        $this->assertTrue($archive->close());

        return 'backup.zip';
    }
}
