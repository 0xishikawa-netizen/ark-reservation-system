<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

final class BackupScheduleTest extends TestCase
{
    public function test_backup_commands_are_scheduled_without_overlap(): void
    {
        $events = collect(app(Schedule::class)->events());

        foreach (['backup:clean', 'backup:run', 'backup:monitor'] as $command) {
            /** @var Event|null $event */
            $event = $events->first(static fn (Event $candidate): bool => str_contains((string) $candidate->command, $command));

            $this->assertNotNull($event, "{$command} がスケジュールされていません。");
            $this->assertTrue($event->withoutOverlapping, "{$command} に withoutOverlapping がありません。");
        }
    }
}
