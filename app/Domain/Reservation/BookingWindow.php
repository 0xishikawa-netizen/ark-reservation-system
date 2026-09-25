<?php

declare(strict_types=1);

namespace App\Domain\Reservation;

use App\Domain\Business\StoreCalendarService;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * 予約受付期間（#11）の唯一の正本。
 *
 * - horizon（予約可能期間）
 *     monthly … 毎月 release_day_of_month に「翌月末まで」を開放。
 *               release 日より前は「今月末まで」。
 *     rolling … 今日から horizon_days 日先まで。
 *     none    … 期間制限なし（未設定の既存環境はこれ。後方互換）。
 * - min_lead_minutes … 直前予約の締切（分）。0 = 直前まで可。
 * - store_calendar_days … 店舗休業日・特別営業時間の正本。
 *
 * horizon / lead は「顧客の予約」にのみ効く（管理者の手動予約は従来どおり期間制限を受けない）。
 * 休業日は誰であっても物理的に不可能な予約として扱う。
 */
final class BookingWindow
{
    public const MODE_NONE = 'none';

    public const MODE_MONTHLY = 'monthly';

    public const MODE_ROLLING = 'rolling';

    public const DEFAULT_RELEASE_DAY = 20;

    public const DEFAULT_HORIZON_DAYS = 60;

    public function __construct(
        private readonly Settings $settings,
        private readonly StoreCalendarService $storeCalendar,
    ) {}

    public function horizonMode(): string
    {
        $mode = $this->settings->get('booking.horizon_mode');

        return in_array($mode, [self::MODE_MONTHLY, self::MODE_ROLLING], true)
            ? (string) $mode
            : self::MODE_NONE;
    }

    public function isHorizonEnforced(): bool
    {
        return $this->horizonMode() !== self::MODE_NONE;
    }

    public function releaseDayOfMonth(): int
    {
        $day = (int) ($this->settings->get('booking.release_day_of_month', self::DEFAULT_RELEASE_DAY) ?? self::DEFAULT_RELEASE_DAY);

        return max(1, min(28, $day));
    }

    public function horizonDays(): int
    {
        $days = (int) ($this->settings->get('booking.horizon_days', self::DEFAULT_HORIZON_DAYS) ?? self::DEFAULT_HORIZON_DAYS);

        return max(1, min(365, $days));
    }

    public function minLeadMinutes(): int
    {
        $minutes = (int) ($this->settings->get('booking.min_lead_minutes', 0) ?? 0);

        return max(0, $minutes);
    }

    /** @return list<string> Y-m-d */
    public function closedDates(): array
    {
        return $this->storeCalendar->closedDates();
    }

    public function isClosedDate(CarbonInterface $date): bool
    {
        return $this->storeCalendar->isClosed($date);
    }

    public function isWithinCalendarHours(CarbonInterface $startsAt, CarbonInterface $endsAt): bool
    {
        return $this->storeCalendar->allowsInterval($startsAt, $endsAt);
    }

    /**
     * 顧客が予約できる最終日（inclusive）。期間制限なしなら null。
     */
    public function lastBookableDate(?CarbonImmutable $now = null): ?CarbonImmutable
    {
        $now ??= CarbonImmutable::now();

        return match ($this->horizonMode()) {
            self::MODE_MONTHLY => $now->day >= $this->releaseDayOfMonth()
                ? $now->addMonthNoOverflow()->endOfMonth()->startOfDay()
                : $now->endOfMonth()->startOfDay(),
            self::MODE_ROLLING => $now->startOfDay()->addDays($this->horizonDays()),
            default => null,
        };
    }

    /**
     * 顧客が予約できる最も早い日時（直前締切を考慮）。
     */
    public function earliestBookableDateTime(?CarbonImmutable $now = null): CarbonImmutable
    {
        $now ??= CarbonImmutable::now();

        return $now->addMinutes($this->minLeadMinutes());
    }

    /**
     * 顧客予約としてこの開始日時が受付可能か。理由（不可の場合）を返す。
     */
    public function customerRejectionReason(
        CarbonImmutable $startsAt,
        ?CarbonImmutable $now = null,
    ): ?string {
        $now ??= CarbonImmutable::now();

        if ($this->isClosedDate($startsAt)) {
            return __('messages.reservation.closed_date');
        }

        if ($startsAt->lessThan($this->earliestBookableDateTime($now))) {
            $minutes = $this->minLeadMinutes();

            return $minutes >= 60 && $minutes % 60 === 0
                ? sprintf('予約は%d時間前までにお願いします。', intdiv($minutes, 60))
                : sprintf('予約は%d分前までにお願いします。', $minutes);
        }

        $last = $this->lastBookableDate($now);

        if ($last !== null && $startsAt->startOfDay()->greaterThan($last)) {
            return sprintf(
                '現在ご予約いただけるのは %s までです。',
                $last->format('n月j日'),
            );
        }

        return null;
    }

    /**
     * @throws ValidationException
     */
    public function assertCustomerBookable(
        CarbonImmutable $startsAt,
        ?CarbonImmutable $now = null,
    ): void {
        $reason = $this->customerRejectionReason($startsAt, $now);

        if ($reason !== null) {
            throw ValidationException::withMessages(['starts_at' => $reason]);
        }
    }
}
