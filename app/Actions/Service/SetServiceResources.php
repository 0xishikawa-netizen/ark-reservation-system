<?php

declare(strict_types=1);

namespace App\Actions\Service;

use App\Models\Service;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * メニューで使える具体ブースと、施術に必要な資格を設定する（Task 11-28）。変更があった時だけ監査に残す。
 */
class SetServiceResources
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  list<int>|null  $boothIds  null は変更しない
     * @param  list<int>|null  $qualificationIds  null は変更しない
     */
    public function execute(Service $service, ?array $boothIds, ?array $qualificationIds, ?Authenticatable $actor = null): Service
    {
        return DB::transaction(function () use ($service, $boothIds, $qualificationIds, $actor): Service {
            if ($boothIds !== null) {
                $changes = $service->booths()->sync(array_values(array_unique($boothIds)));
                if ($this->changed($changes)) {
                    $names = $service->booths()->pluck('name')->implode('、');
                    $this->auditLogger->log('service.booths_set', $service,
                        "メニュー「{$service->name}」の利用ブースを更新（".($names === '' ? '指定なし＝全ブース' : $names).'）', $actor);
                }
            }
            if ($qualificationIds !== null) {
                $changes = $service->qualifications()->sync(array_values(array_unique($qualificationIds)));
                if ($this->changed($changes)) {
                    $names = $service->qualifications()->pluck('name')->implode('、');
                    $this->auditLogger->log('service.qualifications_set', $service,
                        "メニュー「{$service->name}」の必要資格を更新（".($names === '' ? 'なし' : $names).'）', $actor);
                }
            }

            return $service->load(['booths', 'qualifications']);
        });
    }

    /** @param array{attached: array<mixed>, detached: array<mixed>, updated: array<mixed>} $changes */
    private function changed(array $changes): bool
    {
        return $changes['attached'] !== [] || $changes['detached'] !== [];
    }
}
