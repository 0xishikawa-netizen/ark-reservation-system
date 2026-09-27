<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Models\Staff;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * スタッフが実施できる施術（既存 service_staff）と保有資格を設定する（Task 11-28）。変更時だけ監査に残す。
 */
class SetStaffCapabilities
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  list<int>|null  $serviceIds  null は変更しない
     * @param  list<int>|null  $qualificationIds  null は変更しない
     */
    public function execute(Staff $staff, ?array $serviceIds, ?array $qualificationIds, ?Authenticatable $actor = null): Staff
    {
        return DB::transaction(function () use ($staff, $serviceIds, $qualificationIds, $actor): Staff {
            if ($serviceIds !== null) {
                $changes = $staff->services()->sync(array_values(array_unique($serviceIds)));
                if ($changes['attached'] !== [] || $changes['detached'] !== []) {
                    $this->auditLogger->log('staff.services_set', $staff,
                        "スタッフ「{$staff->display_name}」の実施できる施術を更新（".$staff->services()->pluck('name')->implode('、').'）', $actor);
                }
            }
            if ($qualificationIds !== null) {
                $changes = $staff->qualifications()->sync(array_values(array_unique($qualificationIds)));
                if ($changes['attached'] !== [] || $changes['detached'] !== []) {
                    $names = $staff->qualifications()->pluck('name')->implode('、');
                    $this->auditLogger->log('staff.qualifications_set', $staff,
                        "スタッフ「{$staff->display_name}」の保有資格を更新（".($names === '' ? 'なし' : $names).'）', $actor);
                }
            }

            return $staff->load(['services', 'qualifications']);
        });
    }
}
