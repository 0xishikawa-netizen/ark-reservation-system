<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 「staff」「manager」ロールに割り当てる権限（＝見える／操作できる画面）を管理する画面。
 *
 * admin ロールは常に全権限を持つ superuser として扱い、ここでは編集対象にしない
 * （最後の管理者ロックアウト対策と同じ考え方で、admin の機能を自ら奪えないようにする）。
 * customer ロールも編集対象外（管理画面の権限を一切持たない前提）。
 * 'admin.access' はどのロールでも管理画面へ入るための最低限の権限のため、
 * 一覧には出さず常に付与したまま扱う。
 * 'settings.manage'（予約ポリシー設定）と 'integrations.manage'（外部連携設定）は
 * admin 専用の設定系操作として、staff / manager には委譲不可（一覧にも出さない）。
 */
final class RolePermissionController extends Controller
{
    /** 管理者が編集できるロール。 */
    private const EDITABLE_ROLES = ['staff', 'manager'];

    /**
     * この画面からは付け外しできないが、各ロールの現状の付与状態はそのまま保持する権限。
     * （例: manager は初期設定で 'settings.manage' を持つが、この画面のチェックボックスには
     * 出さず、保存時にも意図せず剥奪されないようにする）
     */
    private const PRESERVED_LOCKED_PERMISSIONS = ['settings.manage', 'integrations.manage'];

    /** 画面上でチェックボックスとして出す権限とラベル（責務ごとにグループ化）。 */
    private const PERMISSION_GROUPS = [
        'messages.roles.group_store_operations' => [
            'staff.manage' => 'messages.roles.permission_staff_manage',
            'services.manage' => 'messages.roles.permission_services_manage',
            'booths.manage' => 'messages.roles.permission_booths_manage',
            'shifts.manage' => 'messages.roles.permission_shifts_manage',
        ],
        'messages.roles.group_customers' => [
            'customers.view' => 'messages.roles.permission_customers_view',
            'customers.manage' => 'messages.roles.permission_customers_manage',
        ],
        'messages.roles.group_reservations_payments' => [
            'reservations.view' => 'messages.roles.permission_reservations_view',
            'reservations.manage' => 'messages.roles.permission_reservations_manage',
            'refund.execute' => 'messages.roles.permission_refund_execute',
        ],
        'messages.roles.group_tickets_memberships' => [
            'ticket.grant' => 'messages.roles.permission_ticket_grant',
            'ticket_products.manage' => 'messages.roles.permission_ticket_products_manage',
            'ticket_policy.manage' => 'messages.roles.permission_ticket_policy_manage',
            'membership.manage' => 'messages.roles.permission_membership_manage',
        ],
        'messages.roles.group_reports' => [
            'reports.view' => 'messages.roles.permission_reports_view',
            'sales.view' => 'messages.roles.permission_sales_view',
            'reports.export' => 'messages.roles.permission_reports_export',
            'reports.manage' => 'messages.roles.permission_reports_manage',
            'reports.reconcile' => 'messages.roles.permission_reports_reconcile',
            'historical_data.import' => 'messages.roles.permission_historical_data_import',
        ],
        'messages.roles.group_integrations_system' => [
            'integrations.view' => 'messages.roles.permission_integrations_view',
            'failed_jobs.view' => 'messages.roles.permission_failed_jobs_view',
            'audit_logs.view' => 'messages.roles.permission_audit_logs_view',
        ],
    ];

    public function show(): Response
    {
        $roles = Role::query()
            ->whereIn('name', ['staff', 'manager', 'admin'])
            ->with('permissions:id,name')
            ->get()
            ->keyBy('name');

        return Inertia::render('Admin/Settings/Roles', [
            'groups' => collect(self::PERMISSION_GROUPS)
                ->map(fn (array $permissions, string $label): array => [
                    'label' => __($label),
                    'permissions' => collect($permissions)
                        ->map(fn (string $label, string $name): array => [
                            'name' => $name,
                            'label' => __($label),
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
            'role_permissions' => [
                'staff' => $roles['staff']?->permissions->pluck('name')->values() ?? [],
                'manager' => $roles['manager']?->permissions->pluck('name')->values() ?? [],
                'admin' => $roles['admin']?->permissions->pluck('name')->values() ?? [],
            ],
        ]);
    }

    public function update(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $editablePermissionNames = collect(self::PERMISSION_GROUPS)
            ->flatMap(fn (array $permissions): array => array_keys($permissions))
            ->values()
            ->all();

        $validated = $request->validate([
            'staff' => ['array'],
            'staff.*' => ['string', Rule::in($editablePermissionNames)],
            'manager' => ['array'],
            'manager.*' => ['string', Rule::in($editablePermissionNames)],
        ]);

        DB::transaction(function () use ($validated, $auditLogger, $request): void {
            foreach (self::EDITABLE_ROLES as $roleName) {
                $role = Role::query()
                    ->where('name', $roleName)
                    ->where('guard_name', 'web')
                    ->lockForUpdate()
                    ->firstOrFail();

                // 'admin.access' は一覧に出さないが、外すと管理画面に一切入れなくなるため
                // 常に付与したままにする。
                // PRESERVED_LOCKED_PERMISSIONS はこの画面から変更できないため、
                // 現在の付与状態をそのまま引き継ぐ（保存のたびに意図せず剥奪しない）。
                $preservedNames = $role->permissions
                    ->pluck('name')
                    ->intersect(self::PRESERVED_LOCKED_PERMISSIONS)
                    ->values()
                    ->all();

                $names = array_unique([
                    ...($validated[$roleName] ?? []),
                    'admin.access',
                    ...$preservedNames,
                ]);
                $permissions = Permission::query()
                    ->whereIn('name', $names)
                    ->where('guard_name', 'web')
                    ->get();

                $role->syncPermissions($permissions);

                $auditLogger->log(
                    'roles.permissions_changed',
                    null,
                    sprintf(
                        'ロール「%s」の権限を変更: %s',
                        $roleName,
                        $permissions->pluck('name')->sort()->implode(', '),
                    ),
                    $request->user(),
                );
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return back()->with('success', __('messages.settings.roles_updated'));
    }
}
