<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Integrations;

use App\Domain\Integration\Enum\OutboxStatus;
use App\Domain\Integration\Service\OutboxDispatcher;
use App\Http\Controllers\Controller;
use App\Models\ReservationSyncOutbox;
use App\Queries\ReservationIntegrationStatusQuery;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 店舗スタッフ向けの外部連携ステータス（read-only）。
 * Phase 9 では Conflict の UI 解決は行わない（needs_attention 表示 + Outbox の手動 retry のみ）。
 */
class ReservationIntegrationController extends Controller
{
    public function show(ReservationIntegrationStatusQuery $query): Response
    {
        return Inertia::render('Admin/Integrations/Reservations', $query->get());
    }

    /**
     * failed / needs_attention の Outbox 行を再投入する（機微操作: 再認証 + 監査）。
     */
    public function retryOutbox(
        Request $request,
        ReservationSyncOutbox $reservationSyncOutbox,
        OutboxDispatcher $dispatcher,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        abort_unless(
            in_array($reservationSyncOutbox->status, [OutboxStatus::Failed, OutboxStatus::NeedsAttention], true),
            422,
        );

        $reservationSyncOutbox->forceFill([
            'status' => OutboxStatus::Pending,
            'available_at' => now(),
            'locked_at' => null,
            'locked_by' => null,
        ])->save();

        $auditLogger->log(
            'integration.outbox.retried',
            $reservationSyncOutbox,
            "外部連携 Outbox #{$reservationSyncOutbox->id}（{$reservationSyncOutbox->provider} / {$reservationSyncOutbox->operation->value}）を再投入",
            $request->user(),
        );

        // 即時に 1 回試す（対象行だけを条件付き claim・他行を processing に固定しない・F-11）。
        $claimed = $dispatcher->claimById(
            (int) $reservationSyncOutbox->id,
            'admin:'.($request->user()?->getAuthIdentifier() ?? '0'),
        );
        if ($claimed !== null) {
            $dispatcher->process($claimed);
        }

        return back()->with('success', __('messages.integration.outbox_requeued'));
    }
}
