<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\Reservation\ReservationSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 予約台帳のオンライン予約通知（§34-37）。
 *
 * 対象は「店舗スタッフが手入力した予約ではない」もの（ARK Web / 外部予約連携）。
 * 既読状態は `reservation_notification_dismissals`（管理者ごと）で管理し、
 * localStorage だけに依存しない。Laravel Reverb（WebSocket）でリアルタイム配信し
 * （`App\Events\OnlineReservationCreated`）、接続断・push取りこぼし対策として
 * このクエリによる低頻度ポーリングも並行して維持する（§9-12）。
 */
final class ScheduleNotificationQuery
{
    /** オンライン予約とみなす経路。管理画面からの手入力（ADMIN）は対象外。 */
    private const ONLINE_SOURCES = [
        ReservationSource::ArkWeb->value,
        ReservationSource::Hotpepper->value,
        ReservationSource::Epark->value,
        ReservationSource::SalonBoard->value,
        ReservationSource::PeakManager->value,
        ReservationSource::External->value,
    ];

    /**
     * @return list<array{
     *   id: int, customer_name: string, service_name: string, source: string, source_label: string,
     *   starts_at: string, date: string, created_at: string
     * }>
     */
    public function unreadFor(int $userId, int $lookbackHours = 24, int $limit = 30): array
    {
        $since = CarbonImmutable::now()->subHours($lookbackHours);

        return DB::table('reservations')
            ->join('customers', 'customers.user_id', '=', 'reservations.customer_id')
            ->join('users as customer_users', 'customer_users.id', '=', 'customers.user_id')
            ->join('services', 'services.id', '=', 'reservations.service_id')
            ->leftJoin('reservation_notification_dismissals as dismissals', function ($join) use ($userId): void {
                $join->on('dismissals.reservation_id', '=', 'reservations.id')
                    ->where('dismissals.user_id', '=', $userId);
            })
            ->whereIn('reservations.source', self::ONLINE_SOURCES)
            ->where('reservations.created_at', '>=', $since)
            ->whereNull('dismissals.id')
            ->orderByDesc('reservations.created_at')
            ->limit($limit)
            ->get(self::ROW_COLUMNS)
            ->map(self::mapRow(...))
            ->values()
            ->all();
    }

    /**
     * 1件だけをリアルタイム通知（Reverb broadcast）向けに整形する。オンライン予約経路
     * でなければ null（呼び出し側でイベント発行自体をスキップする判定にも使える）。
     * unreadFor() と全く同じ列・整形ロジックを再利用し、push/polling で見た目がズレないようにする。
     *
     * @return array{id: int, customer_name: string, service_name: string, source: string, source_label: string, starts_at: string, date: string, created_at: string}|null
     */
    public function forReservation(int $reservationId): ?array
    {
        $row = DB::table('reservations')
            ->join('customers', 'customers.user_id', '=', 'reservations.customer_id')
            ->join('users as customer_users', 'customer_users.id', '=', 'customers.user_id')
            ->join('services', 'services.id', '=', 'reservations.service_id')
            ->where('reservations.id', $reservationId)
            ->whereIn('reservations.source', self::ONLINE_SOURCES)
            ->first(self::ROW_COLUMNS);

        return $row === null ? null : self::mapRow($row);
    }

    private const ROW_COLUMNS = [
        'reservations.id',
        'reservations.source',
        'reservations.starts_at',
        'reservations.created_at',
        'customer_users.name as customer_name',
        'services.name as service_name',
    ];

    /** @return array{id: int, customer_name: string, service_name: string, source: string, source_label: string, starts_at: string, date: string, created_at: string} */
    private static function mapRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'customer_name' => (string) $row->customer_name,
            'service_name' => (string) $row->service_name,
            'source' => (string) $row->source,
            'source_label' => ReservationPanelQuery::sourceLabel(
                ReservationSource::from((string) $row->source),
            ),
            'starts_at' => (string) $row->starts_at,
            'date' => substr((string) $row->starts_at, 0, 10),
            'created_at' => (string) $row->created_at,
        ];
    }
}
