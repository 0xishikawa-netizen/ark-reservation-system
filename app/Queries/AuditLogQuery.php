<?php

declare(strict_types=1);

namespace App\Queries;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class AuditLogQuery
{
    /** @return LengthAwarePaginator<int, array{id: int, created_at: string, action: string, entity_type: string|null, entity_id: string|null, summary: string, ip: string|null, actor_name: string|null}> */
    public function paginate(
        ?string $action,
        ?int $actorUserId,
        ?string $entityType,
        ?string $dateFrom,
        ?string $dateTo,
        int $perPage = 30,
    ): LengthAwarePaginator {
        $paginator = DB::table('audit_logs')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.actor_user_id')
            ->select([
                'audit_logs.id',
                'audit_logs.created_at',
                'audit_logs.action',
                'audit_logs.entity_type',
                'audit_logs.entity_id',
                'audit_logs.summary',
                'audit_logs.ip',
                'audit_logs.actor_user_id',
                'users.name as actor_name',
            ])
            ->when($action !== null, fn ($query) => $query->where('audit_logs.action', $action))
            ->when($actorUserId !== null, fn ($query) => $query->where('audit_logs.actor_user_id', $actorUserId))
            ->when($entityType !== null, fn ($query) => $query->where('audit_logs.entity_type', $entityType))
            ->when($dateFrom !== null, fn ($query) => $query->whereDate('audit_logs.created_at', '>=', $dateFrom))
            ->when($dateTo !== null, fn ($query) => $query->whereDate('audit_logs.created_at', '<=', $dateTo))
            ->orderByDesc('audit_logs.id')
            ->paginate($perPage)
            ->withQueryString();

        return $paginator->through(static fn (object $row): array => [
            'id' => (int) $row->id,
            'created_at' => (string) $row->created_at,
            'action' => (string) $row->action,
            'entity_type' => $row->entity_type === null ? null : (string) $row->entity_type,
            'entity_id' => $row->entity_id === null ? null : (string) $row->entity_id,
            // audit_logs 本体は完全な要約を保持するが、ビューア表示ではメールアドレス等の
            // 直接識別子をマスクする（Phase 8 Task 8-7 / PLAN §13 の PII 最小化）。
            'summary' => self::maskPii((string) $row->summary),
            'ip' => $row->ip === null ? null : (string) $row->ip,
            'actor_name' => $row->actor_name === null ? null : (string) $row->actor_name,
        ]);
    }

    /**
     * 要約文中のメールアドレスを local-part の先頭 1 文字だけ残してマスクする。
     *
     * 例: user@example.com -> u***@example.com
     */
    private static function maskPii(string $summary): string
    {
        return (string) preg_replace_callback(
            '/([A-Za-z0-9._%+\-])[A-Za-z0-9._%+\-]*(@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})/',
            static fn (array $m): string => $m[1].'***'.$m[2],
            $summary,
        );
    }

    /** @return list<string> */
    public function distinctActions(): array
    {
        return DB::table('audit_logs')
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->all();
    }
}
