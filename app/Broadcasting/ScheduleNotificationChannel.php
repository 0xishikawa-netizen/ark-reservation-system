<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\User;

/**
 * オンライン予約通知チャンネル（`private-schedule-notifications`）の認可ロジック（§9-12）。
 * routes/channels.php から呼ぶだけの薄いクラスにし、HTTP/ブロードキャスト経路を介さず
 * 直接ユニットテストできるようにしている（実際に `/broadcasting/auth` を叩くテストは
 * ブロードキャスタードライバーの実装差に左右されやすく壊れやすいため）。
 */
final class ScheduleNotificationChannel
{
    public static function authorize(User $user): bool
    {
        return $user->can('reservations.view');
    }
}
