<?php

declare(strict_types=1);

namespace App\Actions\Booth;

use App\Models\Booth;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

class UpdateBooth
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param  array<string, mixed>  $data */
    public function execute(
        Booth $booth,
        array $data,
        ?Authenticatable $actor = null,
    ): Booth {
        return DB::transaction(function () use ($booth, $data, $actor): Booth {
            $data['sort_order'] ??= 0;
            $booth->update($data);

            $this->auditLogger->log(
                'booth.updated',
                $booth,
                "ブース「{$booth->name}」を更新",
                $actor,
            );

            return $booth->refresh();
        });
    }
}
