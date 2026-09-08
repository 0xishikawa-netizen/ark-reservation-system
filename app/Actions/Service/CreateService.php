<?php

declare(strict_types=1);

namespace App\Actions\Service;

use App\Models\Service;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

class CreateService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data, ?Authenticatable $actor = null): Service
    {
        return DB::transaction(function () use ($data, $actor): Service {
            /** @var list<int> $staffIds */
            $staffIds = array_values(array_unique($data['staff_ids'] ?? []));
            unset($data['staff_ids']);

            if (($data['color'] ?? null) === null) {
                unset($data['color']);
            }

            $service = Service::query()->create($data);

            if ($staffIds !== []) {
                $service->staff()->sync($staffIds);
            }

            $this->auditLogger->log(
                'service.created',
                $service,
                "サービス「{$service->name}」を作成",
                $actor,
            );

            return $service->load('staff');
        });
    }
}
