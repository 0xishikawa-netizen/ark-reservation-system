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

Schedule::command('tickets:expire')->dailyAt('03:00')->withoutOverlapping();

Schedule::command('tickets:reconcile')->dailyAt('03:15')->withoutOverlapping();

// 支払い期限切れの仮予約を毎分失効させる（冪等・PLAN §7）。
Schedule::command('payments:expire')->everyMinute()->withoutOverlapping();

// 決済の突合（read-only）。差異があれば非 zero exit で失敗として記録される。
Schedule::command('payments:reconcile')->dailyAt('03:45')->withoutOverlapping();

Schedule::command('memberships:grant-current')->dailyAt('04:00')->withoutOverlapping();

Schedule::command('memberships:expire-grace')->dailyAt('04:15')->withoutOverlapping();

// 利用権の突合（read-only）。差異があれば非 zero exit。
Schedule::command('memberships:reconcile')->dailyAt('04:30')->withoutOverlapping();

// DB 使用量の日次スナップショット（PLAN §14 / Phase 8）。閾値超過で非 zero exit。
Schedule::command('db:snapshot-size')->dailyAt('02:45')->withoutOverlapping();
