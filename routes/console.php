<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('reservations:prune-slots')
    ->dailyAt('03:30')
    ->withoutOverlapping();

// 基本シフトから予約受付期間分の勤務枠を生成する（#11・冪等）。
// monthly 方式なら開放日を迎えた朝に翌月分がまとめて作られる。
Schedule::command('shifts:generate')
    ->dailyAt('06:00')
    ->withoutOverlapping();

Schedule::command('tickets:expire')->dailyAt('03:00')->withoutOverlapping();

Schedule::command('tickets:reconcile')->dailyAt('03:15')->withoutOverlapping();

// 支払い期限切れの仮予約を毎分失効させる（冪等・PLAN §7）。
Schedule::command('payments:expire')->everyMinute()->withoutOverlapping();

// 決済の突合（read-only）。差異があれば非 zero exit で失敗として記録される。
Schedule::command('payments:reconcile')->dailyAt('03:45')->withoutOverlapping();

// timeout / 曖昧応答で pending のまま止まった返金を冪等キーで再送し settle する（Phase 9.5）。
Schedule::command('payments:settle-pending-refunds')->everyTenMinutes()->withoutOverlapping();

Schedule::command('memberships:grant-current')->dailyAt('04:00')->withoutOverlapping();

Schedule::command('memberships:expire-grace')->dailyAt('04:15')->withoutOverlapping();

// 利用権の突合（read-only）。差異があれば非 zero exit。
Schedule::command('memberships:reconcile')->dailyAt('04:30')->withoutOverlapping();

// DB 使用量の日次スナップショット（PLAN §14 / Phase 8）。閾値超過で非 zero exit。
Schedule::command('db:snapshot-size')->dailyAt('02:45')->withoutOverlapping();

// 技術ログの保持期間 prune（webhook_events / audit_logs。open / failed / 金銭・PII は保持）。
Schedule::command('system:prune-technical-logs')->dailyAt('02:40')->withoutOverlapping();

// Phase 9 外部予約連携（Provider 実仕様が無いため間隔は config 化・推測しない）。
Schedule::command('reservations:dispatch-outbox')->everyMinute()->withoutOverlapping();
Schedule::command('reservations:poll-external')
    ->cron((string) config('reservation_integration.inbound.poll_cron', '*/15 * * * *'))
    ->withoutOverlapping();
Schedule::command('reservations:reconcile-providers')
    ->cron((string) config('reservation_integration.reconcile.cron', '0 5 * * *'))
    ->withoutOverlapping();
Schedule::command('reservations:prune-sync-logs')->dailyAt('02:50')->withoutOverlapping();

// DB と storage/app のバックアップ、世代整理、健全性監視。
Schedule::command('backup:clean')->dailyAt('01:00')->withoutOverlapping();
Schedule::command('backup:run')->dailyAt('01:30')->withoutOverlapping();
Schedule::command('backup:monitor')->dailyAt('07:00')->withoutOverlapping();
