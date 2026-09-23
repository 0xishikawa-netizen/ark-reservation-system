<?php

declare(strict_types=1);

namespace App\Domain\Reservation;

use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;

final class WeekAvailabilityBuilder
{
    /**
     * 公開予約画面だけの表示粒度。予約枠・管理画面の5分粒度は変更しない。
     */
    private const DISPLAY_SLOT_MINUTES = 30;

    /** @var list<string> */
    private const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

    public function __construct(
        private readonly AvailabilityService $availabilityService,
        private readonly Settings $settings,
    ) {}

    /**
     * @return array{
     *     days: list<array{date: string, label: string}>,
     *     times: list<string>,
     *     cells: array<string, array<string, 'open'|'some'|'full'>>
     * }
     */
    public function build(int $serviceId, ?int $staffId, CarbonImmutable $startDate): array
    {
        $times = $this->displayTimes($startDate);
        $days = [];
        $cells = [];

        for ($offset = 0; $offset < 7; $offset++) {
            $date = $startDate->addDays($offset)->startOfDay();
            $dateString = $date->toDateString();
            $days[] = [
                'date' => $dateString,
                'label' => sprintf(
                    '%d/%d(%s)',
                    $date->month,
                    $date->day,
                    self::WEEKDAYS[$date->dayOfWeek],
                ),
            ];
            $cells[$dateString] = [];

            $openSlotsByTime = collect($this->availabilityService->openStartTimes(
                $serviceId,
                $staffId,
                null,
                $date,
            ))->keyBy(
                static fn (array $slot): string => CarbonImmutable::parse($slot['starts_at'])->format('H:i'),
            );

            foreach ($times as $time) {
                $startsAt = CarbonImmutable::parse("{$dateString} {$time}:00");
                $slot = $openSlotsByTime->get($time);

                if (! $startsAt->isFuture() || ! is_array($slot)) {
                    $cells[$dateString][$time] = 'full';

                    continue;
                }

                // 指名時は候補がその1名だけなので、人数による「△」は表示しない。
                $cells[$dateString][$time] = $staffId !== null
                    ? 'open'
                    : AvailabilityStatusClassifier::classify(
                        count($slot['available_staff_ids'] ?? []),
                    );
            }
        }

        return [
            'days' => $days,
            'times' => $times,
            'cells' => $cells,
        ];
    }

    /** @return list<string> */
    private function displayTimes(CarbonImmutable $date): array
    {
        $open = CarbonImmutable::parse(sprintf(
            '%s %s',
            $date->toDateString(),
            (string) $this->settings->get(
                'business_hours.open',
                config('reservation.business_hours.open', '10:00'),
            ),
        ));
        $close = CarbonImmutable::parse(sprintf(
            '%s %s',
            $date->toDateString(),
            (string) $this->settings->get(
                'business_hours.close',
                config('reservation.business_hours.close', '22:00'),
            ),
        ));
        $times = [];

        for ($time = $open; $time->lessThan($close); $time = $time->addMinutes(self::DISPLAY_SLOT_MINUTES)) {
            $times[] = $time->format('H:i');
        }

        return $times;
    }
}
