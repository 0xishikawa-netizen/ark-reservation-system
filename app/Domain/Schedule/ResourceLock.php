<?php

declare(strict_types=1);

namespace App\Domain\Schedule;

use App\Models\Booth;
use App\Models\Staff;

/**
 * 予約と予定ブロックの同時作成を直列化するためのリソース行ロック（Task 11-33 M-3 / 11-34 共通化）。
 *
 * 予定ブロックには二重予約防止の一意制約が無いため、予約（ReservationService）と予定ブロック
 * （ScheduleBlockService）は、書き込みトランザクション内でこのロックを取ってから相手側との重複を判定する。
 * デッドロックを避けるため、両者とも必ず「スタッフ ID 順 → ブース ID 順」で固定する。
 * 必ず DB トランザクションの中で呼ぶこと。
 */
final class ResourceLock
{
    /**
     * @param  list<int|null>  $staffIds  変更時は旧・新の両方を渡す（null は無視）
     * @param  list<int|null>  $boothIds  同上
     */
    public static function forUpdate(array $staffIds, array $boothIds): void
    {
        $staffIds = self::normalize($staffIds);
        $boothIds = self::normalize($boothIds);

        if ($staffIds !== []) {
            Staff::withTrashed()
                ->whereIn('user_id', $staffIds)
                ->orderBy('user_id')
                ->lockForUpdate()
                ->get();
        }

        if ($boothIds !== []) {
            Booth::withTrashed()
                ->withoutGlobalScope('sort_order')
                ->whereIn('id', $boothIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }
    }

    /**
     * @param  list<int|null>  $ids
     * @return list<int>
     */
    private static function normalize(array $ids): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            array_filter($ids, static fn (mixed $id): bool => $id !== null),
        )));
        sort($ids);

        return $ids;
    }
}
