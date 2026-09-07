<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\AuditLog;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditLogger
{
    public function log(
        string $action,
        ?Model $entity = null,
        string $summary = '',
        ?Authenticatable $actor = null,
    ): void {
        $actor ??= auth()->user();

        AuditLog::query()->create([
            'actor_user_id' => $actor?->getAuthIdentifier(),
            'action' => $action,
            'entity_type' => $entity?->getMorphClass(),
            'entity_id' => $entity === null ? null : (string) $entity->getKey(),
            'summary' => Str::substr($summary, 0, 500),
            'ip' => app()->bound('request') ? request()->ip() : null,
        ]);
    }
}
