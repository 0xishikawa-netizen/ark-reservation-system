<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Models\DbSizeSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class SnapshotDbSizeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-09 02:45:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_records_a_snapshot_for_today(): void
    {
        $this->artisan('db:snapshot-size')->assertExitCode(0);

        $snapshot = DbSizeSnapshot::query()->firstOrFail();

        $this->assertSame('2026-09-09', $snapshot->captured_on->toDateString());
        $this->assertGreaterThanOrEqual(0, $snapshot->total_mb);
    }

    public function test_running_twice_on_the_same_day_keeps_one_row(): void
    {
        $this->artisan('db:snapshot-size')->assertExitCode(0);
        $this->artisan('db:snapshot-size')->assertExitCode(0);

        $this->assertSame(1, DbSizeSnapshot::query()->count());
    }

    public function test_it_fails_when_the_size_exceeds_the_alert_threshold(): void
    {
        // まず現在の DB サイズを測る（閾値をこの値より下に置いて超過を再現する）。
        $this->artisan('db:snapshot-size')->assertExitCode(0);
        $current = (int) DbSizeSnapshot::query()->value('total_mb');

        if ($current <= 1) {
            $this->markTestSkipped('テスト DB が小さすぎて閾値超過を再現できません。');
        }

        config()->set('retention.db_size_alert_mb', $current - 1);

        $this->artisan('db:snapshot-size')->assertExitCode(1);
    }

    public function test_threshold_of_zero_is_treated_as_unset(): void
    {
        config()->set('retention.db_size_alert_mb', 0);

        $this->artisan('db:snapshot-size')->assertExitCode(0);
    }
}
