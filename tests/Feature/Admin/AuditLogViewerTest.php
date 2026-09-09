<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AuditLogViewerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_customer_cannot_view_audit_logs(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');

        $this->actingAs($customer)
            ->get('/admin/system/audit-logs')
            ->assertForbidden();
    }

    public function test_staff_without_permission_cannot_view_audit_logs(): void
    {
        $staffRole = Role::findByName('staff');
        $staffRole->syncPermissions(['admin.access']);

        $staff = User::factory()->create([
            'two_factor_confirmed_at' => now(),
        ]);
        $staff->assignRole($staffRole);

        $this->actingAs($staff)
            ->get('/admin/system/audit-logs')
            ->assertForbidden();
    }

    public function test_admin_can_view_audit_logs_in_newest_first_order(): void
    {
        $admin = $this->admin();
        $oldest = $this->createAuditLog('payment.refund');
        $middle = $this->createAuditLog('membership.adjust');
        $newest = $this->createAuditLog('ticket_grant');

        $this->actingAs($admin)
            ->get('/admin/system/audit-logs')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/System/AuditLogs')
                ->has('logs.data', 3)
                ->where('logs.data.0.id', $newest->id)
                ->where('logs.data.1.id', $middle->id)
                ->where('logs.data.2.id', $oldest->id));
    }

    public function test_admin_can_filter_audit_logs_by_action(): void
    {
        $admin = $this->admin();
        $this->createAuditLog('payment.refund');
        $this->createAuditLog('membership.adjust');
        $this->createAuditLog('ticket_grant');

        $this->actingAs($admin)
            ->get('/admin/system/audit-logs?action=payment.refund')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/System/AuditLogs')
                ->has('logs.data', 1)
                ->where('logs.data.0.action', 'payment.refund'));
    }

    public function test_admin_sees_actor_name_and_null_for_system_actions(): void
    {
        $admin = $this->admin();
        $actor = User::factory()->create(['name' => '監査 担当者']);
        $this->createAuditLog('payment.refund');
        $this->createAuditLog('payment.refund', $actor);

        $this->actingAs($admin)
            ->get('/admin/system/audit-logs?action=payment.refund')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/System/AuditLogs')
                ->has('logs.data', 2)
                ->where('logs.data.0.actor_name', '監査 担当者')
                ->where('logs.data.1.actor_name', null));
    }

    public function test_email_addresses_in_summaries_are_masked_in_the_viewer(): void
    {
        $admin = $this->admin();
        AuditLog::create([
            'actor_user_id' => null,
            'action' => 'auth.failed',
            'entity_type' => null,
            'entity_id' => null,
            'summary' => 'ログイン失敗: secret.person@example.com',
            'ip' => '127.0.0.1',
        ]);

        $response = $this->actingAs($admin)->get('/admin/system/audit-logs');

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('logs.data.0.summary', 'ログイン失敗: s***@example.com'));

        $this->assertStringNotContainsString('secret.person@example.com', $response->getContent());
    }

    private function admin(): User
    {
        $admin = User::factory()->create([
            'two_factor_confirmed_at' => now(),
        ]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function createAuditLog(string $action, ?User $actor = null): AuditLog
    {
        return AuditLog::create([
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'entity_type' => 'reservation',
            'entity_id' => '123',
            'summary' => "{$action} の監査ログ",
            'ip' => '127.0.0.1',
        ]);
    }
}
