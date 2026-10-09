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
            ->subject($isPendingPayment ? __('messages.guest_reservation_mail.subject_pending') : __('messages.guest_reservation_mail.subject_confirmed'))
            ->greeting(__('messages.guest_reservation_mail.greeting'))
            ->line($isPendingPayment
                ? __('messages.guest_reservation_mail.pending_line')
                : __('messages.guest_reservation_mail.confirmed_line'))
            ->line(__('messages.guest_reservation_mail.date', ['date' => $this->reservation->starts_at->format(__('messages.guest_reservation_mail.date_format'))]))
            ->line(__('messages.guest_reservation_mail.service', ['service' => $this->reservation->service->name]))
            ->line(__('messages.guest_reservation_mail.staff', ['staff' => $this->reservation->staff?->display_name ?? __('messages.guest_reservation_mail.staff_unassigned')]))
            ->action(__('messages.guest_reservation_mail.action'), $this->confirmationUrl)
            ->line(__('messages.guest_reservation_mail.change_hint'));
    }
}
