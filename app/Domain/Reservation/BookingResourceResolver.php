<?php

declare(strict_types=1);

namespace App\Domain\Reservation;

use App\Enums\Reservation\ReservationStatus;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 予約リソースの判定（Task 11-28）。空き枠計算と予約作成・変更の両方がこの1か所を使う。
 *
 * - スタッフ：そのメニューの施術可能スタッフ（service_staff）で、メニューが必要とする資格を全部保有していること。
 * - ブース：メニューに具体的なブースが紐付いていれば、その中の空いている1つ。紐付けが無いメニューは従来どおり
 *   全有効ブースが候補で、ブース指定は任意。
 * 資格名・ブース名をコードにハードコードしない（すべて設定データで判定する）。
 */
final class BookingResourceResolver
{
    /** 予約枠を押さえている状態。空き判定・自動割当はこれらと重ならないことを確認する。 */
    public const HOLDING_STATUSES = [
        ReservationStatus::PendingPayment,
        ReservationStatus::PendingExternalSync,
        ReservationStatus::Confirmed,
    ];

    /**
     * メニューで使える有効なブース。紐付けが無いメニューは空配列（＝制限なし）。
     *
     * @return list<int>
     */
    public function mappedBoothIds(Service $service): array
    {
        return DB::table('booth_service')
            ->join('booths', 'booths.id', '=', 'booth_service.booth_id')
            ->where('booth_service.service_id', $service->id)
            ->where('booths.is_active', true)
            ->orderBy('booths.sort_order')->orderBy('booths.id')
            ->pluck('booths.id')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    /** メニューに具体ブースの紐付けがあるか（無効ブースだけの紐付けも「制限あり」とみなす）。 */
    public function requiresMappedBooth(Service $service): bool
    {
        return DB::table('booth_service')->where('service_id', $service->id)->exists();
    }

    /**
     * 空き判定で候補にするブース。紐付けがあればそのブース、無ければ全有効ブース。
     *
     * @return list<int>
     */
    public function candidateBoothIds(Service $service): array
    {
        if ($this->requiresMappedBooth($service)) {
            return $this->mappedBoothIds($service);
        }

        return DB::table('booths')->where('is_active', true)->orderBy('sort_order')->orderBy('id')
            ->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    /** 指定ブースをこのメニューで使えるか（紐付けが無いメニューは有効ブースなら可）。 */
    public function boothAllowed(Service $service, int $boothId): bool
    {
        return in_array($boothId, $this->candidateBoothIds($service), true);
    }

    /**
     * 施術可能スタッフのうち、必要資格を全部保有している人だけに絞る条件（staff.user_id を持つクエリへ付ける）。
     * 資格が1つも必要でないメニューは絞り込みなし。
     */
    public function whereQualified(Builder|\Illuminate\Database\Eloquent\Builder|BelongsToMany $query, Service $service, string $staffColumn = 'staff.user_id'): void
    {
        $query->whereNotExists(function (Builder $missing) use ($service, $staffColumn): void {
            $missing->selectRaw('1')->from('qualification_service as qs')
                ->where('qs.service_id', $service->id)
                ->whereNotExists(function (Builder $held) use ($staffColumn): void {
                    $held->selectRaw('1')->from('qualification_staff as qst')
                        ->whereColumn('qst.qualification_id', 'qs.qualification_id')
                        ->whereColumn('qst.staff_id', $staffColumn);
                });
        });
    }

    /** スタッフがメニューの必要資格を全部保有しているか。 */
    public function isQualified(Service $service, int $staffId): bool
    {
        return ! DB::table('qualification_service as qs')
            ->where('qs.service_id', $service->id)
            ->whereNotExists(function (Builder $held) use ($staffId): void {
                $held->selectRaw('1')->from('qualification_staff as qst')
                    ->whereColumn('qst.qualification_id', 'qs.qualification_id')
                    ->where('qst.staff_id', $staffId);
            })
            ->exists();
    }

    /** スタッフがメニューを担当できるか（施術可能スタッフ＋必要資格）。 */
    public function canPerform(Service $service, int $staffId): bool
    {
        return DB::table('service_staff')->where('service_id', $service->id)->where('staff_id', $staffId)->exists()
            && $this->isQualified($service, $staffId);
    }

    /**
     * 開始〜終了（終了後バッファを含む）で空いている最初の候補ブース。予約作成時の自動割当に使う。
     * 最終的な二重予約防止は reservation_resource_slots の一意制約が担う。
     *
     * @param  list<int>  $boothIds
     */
    public function firstFreeBooth(array $boothIds, CarbonImmutable $startsAt, CarbonImmutable $endsAt, ?int $ignoreReservationId = null): ?int
    {
        if ($boothIds === []) {
            return null;
        }
        // 予約本体の時間帯（ends_at は終了後バッファを含む）で重なりを判定する。
        $busy = DB::table('reservations')
            ->whereIn('booth_id', $boothIds)
            ->whereIn('status', array_map(static fn (ReservationStatus $status): string => $status->value, self::HOLDING_STATUSES))
            ->where('starts_at', '<', $endsAt->format('Y-m-d H:i:s'))
            ->where('ends_at', '>', $startsAt->format('Y-m-d H:i:s'))
            ->when($ignoreReservationId !== null, fn (Builder $query) => $query->where('id', '!=', $ignoreReservationId))
            ->distinct()->pluck('booth_id')->map(static fn (mixed $id): int => (int) $id)->all();
        $blocked = $startsAt->isSameDay($endsAt)
            ? DB::table('staff_schedule_blocks')->whereIn('booth_id', $boothIds)
                ->whereDate('work_date', $startsAt->toDateString())
                ->whereTime('start_at', '<', $endsAt->format('H:i:s'))
                ->whereTime('end_at', '>', $startsAt->format('H:i:s'))
                ->pluck('booth_id')->map(static fn (mixed $id): int => (int) $id)->all()
            : [];
        foreach ($boothIds as $boothId) {
            if (! in_array($boothId, $busy, true) && ! in_array($boothId, $blocked, true)) {
                return $boothId;
            }
        }

        return null;
    }
}
