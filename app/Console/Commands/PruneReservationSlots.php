<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Reservation\ReservationStatus;
use App\Models\ReservationResourceSlot;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class PruneReservationSlots extends Command
{
    private const BATCH_SIZE = 2000;

    /** @var string */
    protected $signature = 'reservations:prune-slots {--days=} {--dry-run}';

    /** @var string */
    protected $description = '保持期間を過ぎた terminal 予約のリソーススロットを削除する';

    public function handle(): int
    {
        $days = $this->retentionDays();

        if ($days === null) {
            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $query = $this->prunableSlots($cutoff);

        if ((bool) $this->option('dry-run')) {
            $this->info(sprintf('削除対象スロット件数: %d（dry-run: 削除なし）', $query->count()));

            return self::SUCCESS;
        }

        $deleted = 0;

        $query->select('id')->chunkById(
            self::BATCH_SIZE,
            function (Collection $slots) use (&$deleted, $cutoff): void {
                $deleted += $this->prunableSlots($cutoff)
                    ->whereKey($slots->modelKeys())
                    ->delete();
            },
        );

        $this->info(sprintf('削除したスロット件数: %d', $deleted));

        return self::SUCCESS;
    }

    private function retentionDays(): ?int
    {
        $configuredDays = config('retention.prune.reservation_resource_slots.past_days', 14);
        $value = $this->option('days') ?? $configuredDays;

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            $this->error('--days には 0 以上の整数を指定してください。');

            return null;
        }

        $days = (int) $value;

        if ($days < 0) {
            $this->error('--days には 0 以上の整数を指定してください。');

            return null;
        }

        return $days;
    }

    /** @return Builder<ReservationResourceSlot> */
    private function prunableSlots(CarbonInterface $cutoff): Builder
    {
        return ReservationResourceSlot::query()
            ->where('slot_start', '<', $cutoff)
            ->whereHas('reservation', function (Builder $query): void {
                $query->whereIn('status', [
                    ReservationStatus::Completed->value,
                    ReservationStatus::NoShow->value,
                    ReservationStatus::Canceled->value,
                    ReservationStatus::Expired->value,
                ]);
            });
    }
}
