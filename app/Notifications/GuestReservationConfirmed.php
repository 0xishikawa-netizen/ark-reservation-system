<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\Reservation\ReservationStatus;
use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class GuestReservationConfirmed extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Reservation $reservation,
        private readonly string $confirmationUrl,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isPendingPayment = $this->reservation->status === ReservationStatus::PendingPayment;

        return (new MailMessage)
            ->subject($isPendingPayment ? '予約枠を確保しました' : 'ご予約を承りました')
            ->greeting('ご予約ありがとうございます。')
            ->line($isPendingPayment
                ? '現在は仮予約です。確認ページからお支払いを完了してください。'
                : '以下の内容でご予約を承りました。')
            ->line('日時：'.$this->reservation->starts_at->format('Y年n月j日 H:i'))
            ->line('メニュー：'.$this->reservation->service->name)
            ->line('担当：'.($this->reservation->staff?->display_name ?? 'お任せ'))
            ->action('予約内容を確認する', $this->confirmationUrl)
            ->line('予約の変更・キャンセルも確認ページから行えます。');
    }
}
