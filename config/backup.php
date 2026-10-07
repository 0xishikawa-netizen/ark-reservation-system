<?php

declare(strict_types=1);

use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;
use Spatie\DbDumper\Compressors\GzipCompressor;

$backupRoot = env('BACKUP_DISK_ROOT') ?: storage_path('backups');
$maxStorageMb = (int) (env('BACKUP_MAX_STORAGE_MB') ?: 5000);

return [
    'backup' => [
        'name' => env('APP_NAME', 'ARK Reservation'),
        'source' => [
            'files' => [
                'include' => [storage_path('app')],
                'exclude' => array_values(array_unique([
                    $backupRoot,
                    storage_path('backups'),
                    base_path('node_modules'),
                    base_path('vendor'),
                ])),
                'follow_links' => false,
                'ignore_unreadable_directories' => false,
                'relative_path' => base_path(),
            ],
            'databases' => ['mysql'],
        ],
        'database_dump_compressor' => GzipCompressor::class,
        'database_dump_file_timestamp_format' => null,
        'database_dump_filename_base' => 'database',
        'database_dump_file_extension' => '',
        'destination' => [
            'compression_method' => ZipArchive::CM_DEFAULT,
            'compression_level' => 9,
            'filename_prefix' => '',
            'disks' => ['backups'],
            'continue_on_failure' => false,
        ],
        'temporary_directory' => storage_path('app/backup-temp'),
        'password' => env('BACKUP_ARCHIVE_PASSWORD') ?: null,
        'encryption' => env('BACKUP_ARCHIVE_PASSWORD') ? 'default' : 'none',
        'verify_backup' => true,
        'tries' => 1,
        'retry_delay' => 0,
    ],

    'notifications' => [
        'notifications' => [
            BackupHasFailedNotification::class => ['mail'],
            UnhealthyBackupWasFoundNotification::class => ['mail'],
            CleanupHasFailedNotification::class => ['mail'],
            BackupWasSuccessfulNotification::class => ['mail'],
            HealthyBackupWasFoundNotification::class => ['mail'],
            CleanupWasSuccessfulNotification::class => ['mail'],
        ],
        'notifiable' => Notifiable::class,
        'mail' => [
            'to' => env('BACKUP_NOTIFICATION_EMAIL') ?: env('ADMIN_ALERT_EMAIL'),
            'from' => [
                'address' => env('MAIL_FROM_ADDRESS'),
                'name' => env('MAIL_FROM_NAME'),
            ],
        ],
        'slack' => ['webhook_url' => '', 'channel' => null, 'username' => null, 'icon' => null],
        'discord' => ['webhook_url' => '', 'username' => '', 'avatar_url' => ''],
        'webhook' => ['url' => ''],
    ],

    'log_channel' => null,

    'monitor_backups' => [[
        'name' => env('APP_NAME', 'ARK Reservation'),
        'disks' => ['backups'],
        'health_checks' => [
            MaximumAgeInDays::class => 1,
            MaximumStorageInMegabytes::class => $maxStorageMb,
        ],
    ]],

    'cleanup' => [
        'strategy' => DefaultStrategy::class,
        'default_strategy' => [
            'keep_all_backups_for_days' => 7,
            'keep_daily_backups_for_days' => 7,
            'keep_weekly_backups_for_weeks' => 4,
            'keep_monthly_backups_for_months' => 0,
            'keep_yearly_backups_for_years' => 0,
            'delete_oldest_backups_when_using_more_megabytes_than' => $maxStorageMb,
        ],
        'tries' => 1,
        'retry_delay' => 0,
    ],

    'verify_restore' => [
        'database' => env('BACKUP_VERIFY_DATABASE'),
    ],
];
