<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Ticket\UpdateTicketPolicy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateTicketPolicyRequest;
use App\Support\Settings\Settings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class TicketPolicySettingsController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Admin/Settings/Tickets', [
            'policy' => [
                'no_show_policy' => (string) app(Settings::class)
                    ->get('ticket.no_show_policy', 'restore'),
                'expiration_hold_policy' => (string) app(Settings::class)
                    ->get('ticket.expiration_hold_policy', 'preserve_hold'),
            ],
            'options' => [
                'no_show' => [
                    [
                        'value' => 'restore',
                        'label' => '回数を返却する',
                        'description' => 'RESERVE_RELEASE のみ。無断キャンセルでも顧客の利用可能回数が 1 戻ります。',
                    ],
                    [
                        'value' => 'consume',
                        'label' => '1回分を消化する',
                        'description' => 'RESERVE_RELEASE + CONSUME。無断キャンセルで 1 回分を消化し、利用可能回数は戻りません。',
                    ],
                ],
                'expiration_hold' => [
                    [
                        'value' => 'preserve_hold',
                        'label' => '予約分は保持し、予約結果に応じて後処理する',
                        'description' => '有効期限が来ても、予約で確保済みの回数は保持します。来店で消化・キャンセルで返却（返却分は期限切れとして相殺）します。',
                    ],
                ],
            ],
        ]);
    }

    public function update(
        UpdateTicketPolicyRequest $request,
        UpdateTicketPolicy $action,
    ): RedirectResponse {
        $action->execute(
            $request->string('no_show_policy')->toString(),
            $request->string('expiration_hold_policy')->toString(),
            $request->string('reason')->toString(),
            $request->user(),
        );

        return back()->with('success', '回数券運用設定を更新しました。');
    }
}
