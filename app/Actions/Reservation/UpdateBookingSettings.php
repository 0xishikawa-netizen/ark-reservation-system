<?php

declare(strict_types=1);

namespace App\Actions\Reservation;

use App\Domain\Reservation\BookingWindow;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\Settings;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * 予約受付設定（#11 タブ3）を保存する。
 * 既存の settings 基盤（key/value/type）をそのまま使う。
 */
final class UpdateBookingSettings
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{
     *   horizon_mode: string,
     *   horizon_days: int,
     *   release_day_of_month: int,
     *   min_lead_minutes: int,
     *   closed_dates: list<string>,
     * }  $data
     */
    public function execute(array $data, ?Authenticatable $actor = null): void
    {
        $mode = in_array($data['horizon_mode'], [
            BookingWindow::MODE_NONE,
            BookingWindow::MODE_MONTHLY,
            BookingWindow::MODE_ROLLING,
        ], true) ? $data['horizon_mode'] : BookingWindow::MODE_NONE;

        $closedDates = array_values(array_unique(array_filter(
            $data['closed_dates'],
            static fn (string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1,
        )));
        sort($closedDates);

        $this->settings->set('booking.horizon_mode', $mode, 'string');
        $this->settings->set('booking.horizon_days', max(1, min(365, $data['horizon_days'])), 'int');
        $this->settings->set('booking.release_day_of_month', max(1, min(28, $data['release_day_of_month'])), 'int');
        $this->settings->set('booking.min_lead_minutes', max(0, $data['min_lead_minutes']), 'int');
        $this->settings->set('booking.closed_dates', $closedDates, 'json');

        $this->auditLogger->log(
            'booking_settings.updated',
            null,
            sprintf(
                '予約受付設定を更新（%s / 締切%d分 / 休業日%d件）',
                $mode,
                max(0, $data['min_lead_minutes']),
                count($closedDates),
            ),
            $actor,
        );
    }
}
