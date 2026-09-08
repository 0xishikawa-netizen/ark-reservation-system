<?php

declare(strict_types=1);

namespace App\Actions\Booth;

use App\Models\Booth;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

class ToggleBoothActive
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        Booth $booth,
        bool $active,
        ?Authenticatable $actor = null,
    ): Booth {
        return DB::transaction(function () use ($booth, $active, $actor): Booth {
            $booth->update(['is_active' => $active]);

            $this->auditLogger->log(
                'booth.active_changed',
                $booth,
                sprintf(
                    'ブース「%s」を%s',
                    $booth->name,
                    $active ? '有効化' : '無効化',
                ),
                $actor,
            );

            return $booth->refresh();
        });
    }
}
