<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Notification\NotificationSettings;
use App\Http\Controllers\Controller;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class NotificationSettingsController extends Controller
{
    public function show(NotificationSettings $settings): Response
    {
        return Inertia::render('Admin/Settings/Notifications', [
            'settings' => $settings->newReservationSound(),
        ]);
    }

    public function update(
        Request $request,
        NotificationSettings $settings,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'type' => ['required', 'string', Rule::in(NotificationSettings::SOUND_TYPES)],
            'volume' => ['required', 'integer', 'between:'.NotificationSettings::MIN_VOLUME.','.NotificationSettings::MAX_VOLUME],
            'repeat' => ['required', 'string', Rule::in(NotificationSettings::REPEAT_MODES)],
        ]);

        $after = [
            'enabled' => (bool) $validated['enabled'],
            'type' => (string) $validated['type'],
            'volume' => (int) $validated['volume'],
            'repeat' => (string) $validated['repeat'],
        ];
        $before = $settings->newReservationSound();

        if ($before !== $after) {
            $settings->save($after['enabled'], $after['type'], $after['volume'], $after['repeat']);
            $auditLogger->log(
                'notification_settings.updated',
                null,
                sprintf('新規予約の通知音: %s → %s', $this->summary($before), $this->summary($after)),
                $request->user(),
            );
        }

        return back()->with('success', __('messages.settings.notifications_updated'));
    }

    /** @param array{enabled: bool, type: string, volume: int, repeat: string} $sound */
    private function summary(array $sound): string
    {
        return sprintf(
            '%s・%s・音量%d・%s',
            $sound['enabled'] ? 'オン' : 'オフ',
            $sound['type'],
            $sound['volume'],
            $sound['repeat'],
        );
    }
}
