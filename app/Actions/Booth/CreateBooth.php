<?php

declare(strict_types=1);

namespace App\Actions\Booth;

use App\Models\Booth;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

class CreateBooth
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param  array<string, mixed>  $data */
    public function execute(array $data, ?Authenticatable $actor = null): Booth
    {
        return DB::transaction(function () use ($data, $actor): Booth {
            $data['sort_order'] ??= 0;
            $data['is_active'] ??= true;

            $booth = Booth::query()->create($data);

            $this->auditLogger->log(
                'booth.created',
                $booth,
                "ブース「{$booth->name}」を作成",
                $actor,
            );

            return $booth;
        });
    }
}
