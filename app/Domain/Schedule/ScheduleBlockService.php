<?php

declare(strict_types=1);

namespace App\Domain\Schedule;

use App\Enums\Reservation\ReservationStatus;
use App\Enums\Schedule\ScheduleBlockType;
use App\Models\Booth;
use App\Models\Staff;
use App\Models\StaffScheduleBlock;
use App\Models\StaffShift;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\SlotKey;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 予定ブロック（休憩・ミーティング・事務作業・清掃・研修・外出・その他）。
 * Reservation を無理やり流用せず、独立した概念として管理する（§27-28）。
 * 顧客予約ではないため、メール・SMS・Stripe・回数券・月額プランは一切動かさない（§46）。
 */
final class ScheduleBlockService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @throws ValidationException */
    public function create(ScheduleBlockInput $in): StaffScheduleBlock
    {
        $this->validate($in);

        $block = DB::transaction(function () use ($in): StaffScheduleBlock {
            $this->assertNoConflict($in);

            return StaffScheduleBlock::query()->create([
                'staff_id' => $in->staffId,
                'booth_id' => $in->boothId,
                'work_date' => $in->workDate->toDateString(),
                'start_at' => $in->startsAt->format('H:i:s'),
                'end_at' => $in->endsAt->format('H:i:s'),
                'type' => $in->type,
                'title' => $in->title,
                'note' => $in->note,
                'created_by' => $in->actorUserId,
            ]);
        });

        $this->auditLogger->log(
            'schedule_block.created',
            $block,
            $this->summary('予定追加', $block),
            $this->actor($in->actorUserId),
        );

        return $block;
    }

    /** @throws ValidationException */
    public function update(StaffScheduleBlock $block, ScheduleBlockInput $in): StaffScheduleBlock
    {
        $this->validate($in);

        $before = $this->summary('変更前', $block);

        $block = DB::transaction(function () use ($block, $in): StaffScheduleBlock {
            $locked = StaffScheduleBlock::query()
                ->whereKey($block->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertNoConflict($in, excludeBlockId: (int) $locked->id);

            $locked->update([
                'staff_id' => $in->staffId,
                'booth_id' => $in->boothId,
                'work_date' => $in->workDate->toDateString(),
                'start_at' => $in->startsAt->format('H:i:s'),
                'end_at' => $in->endsAt->format('H:i:s'),
                'type' => $in->type,
                'title' => $in->title,
                'note' => $in->note,
            ]);

            return $locked;
        });

        $this->auditLogger->log(
            'schedule_block.updated',
            $block,
            "予定変更 #{$block->id} {$before} → ".$this->summary('変更後', $block),
            $this->actor($in->actorUserId),
        );

        return $block;
    }

    public function delete(StaffScheduleBlock $block, ?Authenticatable $actor): void
    {
        $summary = $this->summary('削除', $block);

        DB::transaction(function () use ($block): void {
            StaffScheduleBlock::query()
                ->whereKey($block->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $block->delete();
        });

        $this->auditLogger->log('schedule_block.deleted', null, "予定削除 #{$block->id} {$summary}", $actor);
    }

    /** @throws ValidationException */
    private function validate(ScheduleBlockInput $in): void
    {
        if ($in->staffId === null && $in->boothId === null) {
            $this->throwValidation('staff_id', __('messages.schedule_block.resource_required'));
        }

        if (! $in->endsAt->isSameDay($in->startsAt) || $in->endsAt->lessThanOrEqualTo($in->startsAt)) {
            $this->throwValidation('end_at', __('messages.common.end_after_start'));
        }

        $slotKey = SlotKey::fromSettings();

        if (! $slotKey->isBoundary($in->startsAt) || ! $slotKey->isBoundary($in->endsAt)) {
            $this->throwValidation('start_at', __('messages.schedule_block.ten_minute_unit'));
        }

        if ($in->type === ScheduleBlockType::Other && ($in->title === null || trim($in->title) === '')) {
            $this->throwValidation('title', __('messages.schedule_block.title_required_for_other'));
        }

        if ($in->staffId !== null) {
            $staff = Staff::query()->find($in->staffId);

            if ($staff === null) {
                $this->throwValidation('staff_id', __('messages.schedule_block.staff_not_found'));
            }

            // 原則、勤務時間外へは予定ブロックを追加しない（既存に「勤務前ミーティング等」を
            // 許容する仕組みは無いため、無理に許可しない・§42）。
            $withinShift = StaffShift::query()
                ->where('staff_id', $in->staffId)
                ->whereDate('work_date', $in->workDate->toDateString())
                ->whereTime('start_at', '<=', $in->startsAt->format('H:i:s'))
                ->whereTime('end_at', '>=', $in->endsAt->format('H:i:s'))
                ->exists();

            if (! $withinShift) {
                $this->throwValidation('start_at', __('messages.reservation.outside_shift'));
            }
        }

        if ($in->boothId !== null) {
            $booth = Booth::query()->find($in->boothId);

            if ($booth === null) {
                $this->throwValidation('booth_id', __('messages.schedule_block.booth_not_found'));
            }
        }
    }

    /** @throws ValidationException */
    private function assertNoConflict(ScheduleBlockInput $in, ?int $excludeBlockId = null): void
    {
        // 予約がある時間帯へは予定ブロックを追加できない（§41）。
        if ($in->staffId !== null && $this->hasReservationOverlap('staff_id', $in->staffId, $in)) {
            $this->throwValidation('start_at', __('messages.schedule_block.reservation_overlap'));
        }

        if ($in->boothId !== null && $this->hasReservationOverlap('booth_id', $in->boothId, $in)) {
            $this->throwValidation('start_at', __('messages.schedule_block.reservation_overlap'));
        }

        // 他の予定ブロックとの重複も禁止する。
        if ($in->staffId !== null
            && $this->hasBlockOverlap('staff_id', $in->staffId, $in, $excludeBlockId)) {
            $this->throwValidation('start_at', __('messages.schedule_block.block_overlap'));
        }

        if ($in->boothId !== null
            && $this->hasBlockOverlap('booth_id', $in->boothId, $in, $excludeBlockId)) {
            $this->throwValidation('start_at', __('messages.schedule_block.block_overlap'));
        }
    }

    private function hasReservationOverlap(string $column, int $resourceId, ScheduleBlockInput $in): bool
    {
        return DB::table('reservations')
            ->where($column, $resourceId)
            ->whereIn('status', [
                ReservationStatus::PendingPayment->value,
                ReservationStatus::PendingExternalSync->value,
                ReservationStatus::Confirmed->value,
            ])
            ->where('starts_at', '<', $in->endsAt->format('Y-m-d H:i:s'))
            ->where('ends_at', '>', $in->startsAt->format('Y-m-d H:i:s'))
            ->exists();
    }

    private function hasBlockOverlap(
        string $column,
        int $resourceId,
        ScheduleBlockInput $in,
        ?int $excludeBlockId,
    ): bool {
        return DB::table('staff_schedule_blocks')
            ->where($column, $resourceId)
            ->whereDate('work_date', $in->workDate->toDateString())
            ->when($excludeBlockId !== null, fn ($query) => $query->where('id', '!=', $excludeBlockId))
            ->whereTime('start_at', '<', $in->endsAt->format('H:i:s'))
            ->whereTime('end_at', '>', $in->startsAt->format('H:i:s'))
            ->exists();
    }

    private function summary(string $label, StaffScheduleBlock $block): string
    {
        $target = $block->staff_id !== null
            ? 'スタッフ#'.$block->staff_id
            : 'ブース#'.$block->booth_id;

        return "{$label}: {$target} {$block->work_date?->toDateString()} {$block->start_at}〜{$block->end_at} ({$block->type?->label()})";
    }

    private function actor(?int $actorUserId): ?Authenticatable
    {
        if ($actorUserId !== null) {
            return User::query()->find($actorUserId);
        }

        return auth()->user();
    }

    /** @throws ValidationException */
    private function throwValidation(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
