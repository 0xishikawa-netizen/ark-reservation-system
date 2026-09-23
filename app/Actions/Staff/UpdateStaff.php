<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Models\Staff;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class UpdateStaff
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param  array<string, mixed>  $data */
    public function execute(
        Staff $staff,
        array $data,
        ?Authenticatable $actor = null,
    ): Staff {
        return DB::transaction(function () use ($staff, $data, $actor): Staff {
            $user = $staff->user()->firstOrFail();
            $oldRole = $user->getRoleNames()->first();
            $newRole = $data['role'] ?? null;

            if (array_key_exists('is_active', $data)) {
                $this->applyLoginAccess($staff, $user, (bool) $data['is_active'], $actor);
            }

            $attributes = [
                'display_name' => $data['display_name'],
            ];

            if (array_key_exists('color', $data)) {
                $attributes['color'] = $data['color'] ?? '#888888';
            }

            if (array_key_exists('is_bookable', $data)) {
                $attributes['is_bookable'] = $data['is_bookable'];
            }

            if (array_key_exists('sort_order', $data)) {
                $attributes['sort_order'] = $data['sort_order'] ?? 0;
            }

            $staff->update($attributes);

            if (is_string($newRole) && $newRole !== $oldRole) {
                // 同時降格でも管理者不在にならないよう admin ロール行をロックする。
                Role::query()
                    ->where('name', 'admin')
                    ->where('guard_name', 'web')
                    ->lockForUpdate()
                    ->firstOrFail();

                $user->syncRoles([$newRole]);

                if (! User::role('admin')->exists()) {
                    throw ValidationException::withMessages([
                        'role' => __('messages.staff.cannot_demote_last_admin'),
                    ]);
                }

                $this->auditLogger->log(
                    'staff.role_changed',
                    $staff,
                    sprintf(
                        'スタッフ「%s」のロールを %s → %s に変更',
                        $staff->display_name,
                        $oldRole ?? '未設定',
                        $newRole,
                    ),
                    $actor,
                );
            }

            $this->auditLogger->log(
                'staff.updated',
                $staff,
                "スタッフ「{$staff->display_name}」を更新",
                $actor,
            );

            return $staff->refresh();
        });
    }

    /**
     * ログイン可否（is_active）を切り替える。
     * - 自分自身を無効化することはできない（即ロックアウトを防ぐ）。
     * - 有効な admin が居なくなる変更はできない（最後の管理者ロックアウト対策）。
     */
    private function applyLoginAccess(Staff $staff, User $user, bool $isActive, ?Authenticatable $actor): void
    {
        if ($isActive === $user->is_active) {
            return;
        }

        if (! $isActive) {
            if ($actor !== null && (int) $actor->getAuthIdentifier() === (int) $user->getKey()) {
                throw ValidationException::withMessages([
                    'is_active' => __('messages.staff.cannot_disable_self'),
                ]);
            }

            if ($user->hasRole('admin')) {
                $remainingActiveAdmins = User::role('admin')
                    ->where('is_active', true)
                    ->where('id', '!=', $user->getKey())
                    ->exists();

                if (! $remainingActiveAdmins) {
                    throw ValidationException::withMessages([
                        'is_active' => __('messages.staff.cannot_disable_last_admin'),
                    ]);
                }
            }
        }

        $user->update(['is_active' => $isActive]);

        $this->auditLogger->log(
            'staff.login_access_changed',
            $staff,
            sprintf(
                'スタッフ「%s」のログインを%sにしました',
                $staff->display_name,
                $isActive ? '許可' : '無効化',
            ),
            $actor,
        );
    }
}
