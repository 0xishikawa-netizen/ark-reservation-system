<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 9.5: 技術ログの保持期間 prune。open / failed / 金銭・PII は保持する。
 */
final class PruneTechnicalLogsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function webhookEvent(string $eventId, string $status, int $ageDays): void
    {
        $at = now()->subDays($ageDays);
        DB::table('webhook_events')->insert([
            'stripe_event_id' => $eventId,
            'type' => 'payment_intent.succeeded',
            'status' => $status,
            'received_at' => $at,
            'attempts' => 1,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function auditLog(string $action, int $ageDays): void
    {
        $log = AuditLog::query()->create([
            'actor_user_id' => null,
            'action' => $action,
            'summary' => 'x',
        ]);
        AuditLog::query()->whereKey($log->id)->update(['created_at' => now()->subDays($ageDays)]);
    }

    public function test_it_prunes_old_non_failed_webhook_events_but_keeps_failed_and_recent(): void
    {
        config(['retention.prune.webhook_events.success_days' => 90]);

        $this->webhookEvent('evt_old_ok', 'processed', ageDays: 120);
        $this->webhookEvent('evt_old_failed', 'failed', ageDays: 120);
        $this->webhookEvent('evt_recent_ok', 'processed', ageDays: 10);

        $this->artisan('system:prune-technical-logs')->assertExitCode(0);

        $this->assertDatabaseMissing('webhook_events', ['stripe_event_id' => 'evt_old_ok']);
        $this->assertDatabaseHas('webhook_events', ['stripe_event_id' => 'evt_old_failed']);
        $this->assertDatabaseHas('webhook_events', ['stripe_event_id' => 'evt_recent_ok']);
    }

    public function test_it_keeps_money_and_pii_audit_logs_forever_and_prunes_the_rest_after_retention(): void
    {
        config(['retention.prune.audit_logs.low_value_days' => 365]);

        $this->auditLog('auth.login', ageDays: 400);            // prune
        $this->auditLog('reservation.canceled', ageDays: 400);  // prune (no keep token)
        $this->auditLog('payment.refund', ageDays: 400);        // KEEP (money)
        $this->auditLog('reservation.cancel_refunded', ageDays: 400); // KEEP (contains "refund")
        $this->auditLog('ticket.grant', ageDays: 400);          // KEEP
        $this->auditLog('membership.adjusted', ageDays: 400);   // KEEP
        $this->auditLog('auth.login', ageDays: 10);             // KEEP (recent)

        $this->artisan('system:prune-technical-logs')->assertExitCode(0);

        $this->assertSame(1, AuditLog::query()->where('action', 'auth.login')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'reservation.canceled')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'payment.refund')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'reservation.cancel_refunded')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'ticket.grant')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'membership.adjusted')->count());
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $this->webhookEvent('evt_dry', 'processed', ageDays: 200);
        $this->auditLog('auth.login', ageDays: 500);

        $this->artisan('system:prune-technical-logs --dry-run')->assertExitCode(0);

        $this->assertDatabaseHas('webhook_events', ['stripe_event_id' => 'evt_dry']);
        $this->assertSame(1, DB::table('audit_logs')->count());
    }
}
