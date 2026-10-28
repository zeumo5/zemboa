<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmployeeInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('employee.invitation.form', ['token' => $this->token, 'email' => $notifiable->email]);

        return (new MailMessage)->subject(__('team.mail_subject'))->greeting(__('team.mail_greeting'))
            ->line(__('team.mail_intro'))->line(__('team.mail_action'))->action(__('team.activate'), $url)
            ->line(__('team.mail_expiry', ['minutes' => config('auth.passwords.users.expire')]))->line(__('team.mail_ignore'));
    }
}
