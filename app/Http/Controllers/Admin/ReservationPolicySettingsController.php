<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Reservation\UpdateReservationPolicy;
use App\Domain\Reservation\CancellationPolicyResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateReservationPolicyRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class ReservationPolicySettingsController extends Controller
{
    public function show(CancellationPolicyResolver $resolver): Response
    {
        return Inertia::render('Admin/Settings/ReservationPolicy', [
            'policy' => [
                'tiers' => $resolver->tiers(),
                'no_show_refund_percent' => $resolver->noShowRefundPercent(),
            ],
        ]);
    }

    public function update(
        UpdateReservationPolicyRequest $request,
        UpdateReservationPolicy $action,
    ): RedirectResponse {
        $validated = $request->validated();
        /** @var list<array{min_hours_before: int, refund_percent: int}> $tiers */
        $tiers = $validated['tiers'];

        $action->execute(
            $tiers,
            (int) $validated['no_show_refund_percent'],
            $request->user(),
        );

        return back()->with('success', '予約キャンセルポリシーを更新しました。');
    }
}
