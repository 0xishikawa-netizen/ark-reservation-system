<?php

declare(strict_types=1);

namespace App\Actions\Service;

use App\Models\Service;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

class UpdateService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param  array<string, mixed>  $data */
    public function execute(
        Service $service,
        array $data,
        ?Authenticatable $actor = null,
    ): Service {
        return DB::transaction(function () use ($service, $data, $actor): Service {
            unset($data['staff_ids']);

            if (array_key_exists('color', $data) && $data['color'] === null) {
                $data['color'] = '#607d8b';
            }

            $service->update($data);

            $this->auditLogger->log(
                'service.updated',
                $service,
                "サービス「{$service->name}」を更新",
                $actor,
            );

            return $service->refresh();
        });
    }
}
