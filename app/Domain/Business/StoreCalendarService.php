<?php

declare(strict_types=1);

namespace App\Domain\Business;

use App\Models\StoreCalendarDay;
use App\Support\Audit\AuditLogger;
use App\Support\Business\BusinessTime;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StoreCalendarService
{
    public const STATUS_NORMAL = 'normal';

    public function __construct(
        private readonly Settings $settings,
        private readonly BusinessTime $businessTime,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array{business_date:string,status:string,opens_at:string|null,closes_at:string|null,note:string|null,is_exception:bool}
     */
    public function resolve(CarbonInterface|string $date): array
    {
        $businessDate = $this->toBusinessDate($date);
        $exception = StoreCalendarDay::query()
            ->whereDate('business_date', $businessDate)
            ->first();

        if ($exception !== null) {
            return [
                'business_date' => $businessDate,
                'status' => (string) $exception->status,
                'opens_at' => $this->time($exception->opens_at),
                'closes_at' => $this->time($exception->closes_at),
                'note' => $exception->note,
                'is_exception' => true,
            ];
        }

        return [
            'business_date' => $businessDate,
            'status' => self::STATUS_NORMAL,
            'opens_at' => (string) ($this->settings->get(
                'business_hours.open',
                config('reservation.business_hours.open', '10:00'),
            ) ?? config('reservation.business_hours.open', '10:00')),
            'closes_at' => (string) ($this->settings->get(
                'business_hours.close',
                config('reservation.business_hours.close', '22:00'),
            ) ?? config('reservation.business_hours.close', '22:00')),
            'note' => null,
            'is_exception' => false,
        ];
    }

    /**
     * 同じ店舗カレンダー正本を月次帳票向けに一括取得する。
     *
     * @return array<string,array{business_date:string,status:string,opens_at:string|null,closes_at:string|null,note:string|null,is_exception:bool}>
     */
    public function resolveRange(CarbonInterface $from, CarbonInterface $to): array
    {
        $first = $this->toBusinessDate($from);
        $last = $this->toBusinessDate($to);
        $exceptions = StoreCalendarDay::query()->whereBetween('business_date', [$first, $last])
            ->get()->keyBy(static fn (StoreCalendarDay $day): string => $day->business_date->toDateString());
        $normalOpen = (string) ($this->settings->get('business_hours.open', config('reservation.business_hours.open', '10:00'))
            ?? config('reservation.business_hours.open', '10:00'));
        $normalClose = (string) ($this->settings->get('business_hours.close', config('reservation.business_hours.close', '22:00'))
            ?? config('reservation.business_hours.close', '22:00'));
        $days = [];
        for ($day = CarbonImmutable::parse($first, $this->businessTime->timezone()); $day->toDateString() <= $last; $day = $day->addDay()) {
            $date = $day->toDateString();
            $exception = $exceptions->get($date);
            $days[$date] = [
                'business_date' => $date,
                'status' => $exception?->status ?? self::STATUS_NORMAL,
                'opens_at' => $exception === null ? $normalOpen : $this->time($exception->opens_at),
                'closes_at' => $exception === null ? $normalClose : $this->time($exception->closes_at),
                'note' => $exception?->note,
                'is_exception' => $exception !== null,
            ];
        }

        return $days;
    }

    public function isClosed(CarbonInterface|string $date): bool
    {
        return $this->resolve($date)['status'] === StoreCalendarDay::STATUS_CLOSED;
    }

    /** 休業と特別営業時間だけを判定し、通常日の既存予約挙動は変えない。 */
    public function allowsInterval(CarbonInterface $startsAt, CarbonInterface $endsAt): bool
    {
        if (! $startsAt->isSameDay($endsAt)) {
            return false;
        }

        $day = $this->resolve($startsAt);

        if ($day['status'] === StoreCalendarDay::STATUS_CLOSED) {
            return false;
        }

        if ($day['status'] !== StoreCalendarDay::STATUS_SPECIAL_HOURS) {
            return true;
        }

        return $day['opens_at'] !== null
            && $day['closes_at'] !== null
            && $startsAt->format('H:i') >= $day['opens_at']
            && $endsAt->format('H:i') <= $day['closes_at'];
    }

    /** @return list<string> */
    public function closedDates(): array
    {
        return StoreCalendarDay::query()
            ->where('status', StoreCalendarDay::STATUS_CLOSED)
            ->orderBy('business_date')
            ->pluck('business_date')
            ->map(static fn (mixed $date): string => CarbonImmutable::parse((string) $date)->toDateString())
            ->values()
            ->all();
    }

    /** @param array{business_date:string,status:string,opens_at?:string|null,closes_at?:string|null,note?:string|null} $data */
    public function save(array $data, ?Authenticatable $actor): StoreCalendarDay
    {
        $status = $data['status'];
        $opensAt = $data['opens_at'] ?? null;
        $closesAt = $data['closes_at'] ?? null;

        if (! in_array($status, [StoreCalendarDay::STATUS_CLOSED, StoreCalendarDay::STATUS_SPECIAL_HOURS], true)) {
            throw ValidationException::withMessages(['status' => __('messages.business.calendar_invalid_status')]);
        }

        if ($status === StoreCalendarDay::STATUS_SPECIAL_HOURS) {
            if (! is_string($opensAt) || ! is_string($closesAt) || $opensAt >= $closesAt) {
                throw ValidationException::withMessages(['closes_at' => __('messages.business.calendar_hours_invalid')]);
            }
        } else {
            $opensAt = null;
            $closesAt = null;
        }

        return DB::transaction(function () use ($data, $status, $opensAt, $closesAt, $actor): StoreCalendarDay {
            $day = StoreCalendarDay::query()
                ->where('business_date', $data['business_date'])
                ->lockForUpdate()
                ->first();

            $attributes = [
                'status' => $status,
                'opens_at' => $opensAt,
                'closes_at' => $closesAt,
                'note' => $data['note'] ?? null,
                'updated_by' => $actor?->getAuthIdentifier(),
            ];

            if ($day === null) {
                $day = StoreCalendarDay::query()->create([
                    'business_date' => $data['business_date'],
                    ...$attributes,
                ]);
                $action = 'store_calendar.created';
            } else {
                $day->update($attributes);
                $action = 'store_calendar.updated';
            }

            $this->auditLogger->log(
                $action,
                $day,
                sprintf('店舗カレンダー %s を %s に設定', $data['business_date'], $status),
                $actor,
            );

            return $day->refresh();
        });
    }

    public function clear(StoreCalendarDay $day, ?Authenticatable $actor): void
    {
        DB::transaction(function () use ($day, $actor): void {
            $locked = StoreCalendarDay::query()->whereKey($day->getKey())->lockForUpdate()->firstOrFail();
            $date = $locked->business_date->toDateString();
            $this->auditLogger->log('store_calendar.cleared', $locked, "店舗カレンダー {$date} を通常営業へ戻す", $actor);
            $locked->delete();
        });
    }

    /** @param list<string> $dates */
    public function replaceClosedDates(array $dates, ?Authenticatable $actor): void
    {
        $dates = array_values(array_unique($dates));
        sort($dates);

        DB::transaction(function () use ($dates, $actor): void {
            StoreCalendarDay::query()
                ->where('status', StoreCalendarDay::STATUS_CLOSED)
                ->lockForUpdate()
                ->get()
                ->each(function (StoreCalendarDay $day) use ($dates): void {
                    if (! in_array($day->business_date->toDateString(), $dates, true)) {
                        $day->delete();
                    }
                });

            foreach ($dates as $date) {
                $existing = StoreCalendarDay::query()->where('business_date', $date)->lockForUpdate()->first();

                // 特別営業時間は専用設定画面の明示操作なしに休業日へ上書きしない。
                if ($existing?->status === StoreCalendarDay::STATUS_SPECIAL_HOURS) {
                    continue;
                }

                StoreCalendarDay::query()->updateOrCreate(
                    ['business_date' => $date],
                    [
                        'status' => StoreCalendarDay::STATUS_CLOSED,
                        'opens_at' => null,
                        'closes_at' => null,
                        'note' => $existing?->note,
                        'updated_by' => $actor?->getAuthIdentifier(),
                    ],
                );
            }
        });
    }

    private function toBusinessDate(CarbonInterface|string $date): string
    {
        if ($date instanceof CarbonInterface) {
            return $this->businessTime->businessDate($date)->toDateString();
        }

        // Y-m-d は時刻変換せず店舗営業日、時刻付きはJSTへ変換した日を扱う。
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            return CarbonImmutable::createFromFormat('!Y-m-d', $date, $this->businessTime->timezone())->toDateString();
        }

        return $this->businessTime->businessDate($date)->toDateString();
    }

    private function time(mixed $value): ?string
    {
        return is_string($value) ? substr($value, 0, 5) : null;
    }
}
