<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Queries\AuditLogQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AuditLogController extends Controller
{
    public function index(Request $request, AuditLogQuery $query): Response
    {
        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:60'],
            'actor_user_id' => ['nullable', 'integer'],
            'entity_type' => ['nullable', 'string', 'max:80'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $action = $this->nullableString($validated['action'] ?? null);
        $actorUserId = isset($validated['actor_user_id'])
            ? (int) $validated['actor_user_id']
            : null;
        $entityType = $this->nullableString($validated['entity_type'] ?? null);
        $dateFrom = $this->nullableString($validated['date_from'] ?? null);
        $dateTo = $this->nullableString($validated['date_to'] ?? null);

        return Inertia::render('Admin/System/AuditLogs', [
            'logs' => $query->paginate(
                $action,
                $actorUserId,
                $entityType,
                $dateFrom,
                $dateTo,
            ),
            'actions' => $query->distinctActions(),
            'filters' => [
                'action' => $action,
                'actor_user_id' => $actorUserId,
                'entity_type' => $entityType,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ]);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
