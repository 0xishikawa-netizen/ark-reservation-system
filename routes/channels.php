<?php

use App\Broadcasting\ScheduleNotificationChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// オンライン予約通知（§9-12）。台帳閲覧権限を持つ管理者だけが購読できる。
// 認可ロジック自体は ScheduleNotificationChannel::authorize() に切り出し、
// HTTP/ブロードキャスト経路を介さずユニットテストできるようにしている。
// 配列コールバック（[Class::class, 'method']）は Broadcaster::extractParameters() が
// ReflectionFunction で読もうとして TypeError になるため、必ず Closure 経由で呼ぶ。
Broadcast::channel('schedule-notifications', function ($user) {
    return ScheduleNotificationChannel::authorize($user);
});
