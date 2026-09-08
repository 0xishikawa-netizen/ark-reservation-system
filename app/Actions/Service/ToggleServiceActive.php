<?php

declare(strict_types=1);

namespace App\Actions\Service;

use App\Models\Service;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

class ToggleServiceActive
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        Service $service,
        bool $active,
        ?Authenticatable $actor = null,
    ): Service {
        return DB::transaction(function () use ($service, $active, $actor): Service {
            $service->update(['is_active' => $active]);

            $this->auditLogger->log(
                'service.active_changed',
                $service,
                sprintf(
                    'サービス「%s」を%s',
                    $service->name,
                    $active ? '有効化' : '無効化',
                ),
                $actor,
            );

            return $service->refresh();
        });
    }
}
