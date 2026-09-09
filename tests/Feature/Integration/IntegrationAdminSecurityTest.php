<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Domain\Integration\Enum\OutboxStatus;
use App\Domain\Integration\Provider\PeakManagerReservationProvider;
use App\Domain\Integration\Service\InboundReservationSync;
use App\Models\ReservationSyncEvent;
use App\Models\ReservationSyncOutbox;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\IntegrationTestHelpers;
use Tests\TestCase;

/**
 * Phase 9 Admin / Authorization / Secret / PII。
 */
final class IntegrationAdminSecurityTest extends TestCase
{
    use IntegrationTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-20 09:00:00');
        $this->seed(RolePermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        if ($role !== 'customer') {
            $user->forceFill(['two_factor_secret' => encrypt('x'), 'two_factor_confirmed_at' => now()])->save();
        }

        return $user;
    }

    // 38 & 39. customer / staff は Integration 管理へ入れない
    public function test_customer_and_staff_cannot_view_integration_status(): void
    {
        $this->actingAs($this->user('customer'))->get('/admin/integrations/reservations')->assertForbidden();
        $this->actingAs($this->user('staff'))
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->get('/admin/integrations/reservations')->assertForbidden();
    }

    public function test_manager_can_view_but_not_retry(): void
    {
        $this->actingAs($this->user('manager'))
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->get('/admin/integrations/reservations')->assertOk();
    }

    public function test_admin_can_view_status_page(): void
    {
        $this->useMockProvider();
        $this->actingAs($this->user('admin'))
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->get('/admin/integrations/reservations')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Integrations/Reservations')->has('providers')->has('recent_events'));
    }

    // 40 & 42. status ページに secret / raw payload / credential が出ない
    public function test_status_page_exposes_no_secret_or_raw_payload(): void
    {
        config()->set('reservation_integration.providers.peak_manager.api_key', 'super-secret-key-value');
        $this->useMockProvider();
        [$c, $s, $st] = $this->reservationMasters();
        $this->arkReservation($c, $s, $st);

        $html = $this->actingAs($this->user('admin'))
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->get('/admin/integrations/reservations')->getContent();

        $this->assertStringNotContainsString('super-secret-key-value', $html);
        $this->assertStringNotContainsString('api_key', $html);
        $this->assertStringNotContainsString('payload_json', $html);
    }

    // retry は integrations.manage + password.confirm
    public function test_retry_outbox_requires_manage_permission_and_reauth(): void
    {
        $this->useMockProvider();
        [$c, $s, $st] = $this->reservationMasters();
        $reservation = $this->arkReservation($c, $s, $st);
        $outbox = ReservationSyncOutbox::query()->create([
            'provider' => 'mock', 'reservation_id' => $reservation->id, 'operation' => 'create',
            'idempotency_key' => 'rsv-out:create:'.$reservation->id.':1', 'status' => 'needs_attention',
            'attempts' => 6, 'available_at' => now(), 'correlation_id' => (string) Str::uuid(),
        ]);

        // manager（manage 権限なし）→ 403
        $this->actingAs($this->user('manager'))
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->post("/admin/integrations/reservations/outbox/{$outbox->id}/retry")->assertForbidden();

        // admin だが password 未確認 → password.confirm へ
        $this->actingAs($this->user('admin'))
            ->withSession(['auth.password_confirmed_at' => null])
            ->post("/admin/integrations/reservations/outbox/{$outbox->id}/retry")
            ->assertRedirect(route('password.confirm'));

        // admin + 再認証済み → 実行 + 監査
        $this->actingAs($this->user('admin'))
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->post("/admin/integrations/reservations/outbox/{$outbox->id}/retry")->assertRedirect();

        $this->assertDatabaseHas('audit_logs', ['action' => 'integration.outbox.retried']);
        $this->assertSame(OutboxStatus::Succeeded, $outbox->fresh()->status);
    }

    // 41. sync event に PII が入らない（外部 ID は mask）
    public function test_sync_events_never_store_pii(): void
    {
        $this->useMockProvider();
        [$c, $s, $st] = $this->reservationMasters();
        app(InboundReservationSync::class)->apply(
            $this->externalData('ext-abcdef123456', $s, $c, $st),
        );

        foreach (ReservationSyncEvent::all() as $event) {
            foreach ($event->getAttributes() as $col => $value) {
                if (is_string($value)) {
                    $this->assertStringNotContainsString('テスト 太郎', $value, $col);
                    $this->assertStringNotContainsString('****1234', $value, $col);
                    $this->assertStringNotContainsString('ext-abcdef123456', $value, $col);
                }
            }
        }
    }

    // skeleton provider は capability 0（推測実装が入っていない）
    public function test_skeleton_providers_have_no_capabilities(): void
    {
        $this->assertSame([], (new PeakManagerReservationProvider)->capabilities()->toArray());
    }
}
