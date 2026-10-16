<?php
namespace App\Notifications;


use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmployeeInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $token
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('employee.invitation.form', [
            'token' => $this->token,
            'email' => $notifiable->email,
        ]);

        return (new MailMessage)
            ->subject('Invitation à rejoindre ZEMBOA')
            ->greeting('Bienvenue sur ZEMBOA !')
            ->line(
                'Vous avez été invité à rejoindre une équipe sur ZEMBOA.'
            )
            ->line(
                'Cliquez sur le bouton ci-dessous pour définir votre mot de passe.'
            )
            ->action('Activer mon compte', $url)
            ->line('Ce lien expire après 60 minutes.')
            ->line(
                'Si vous ne connaissez pas cette invitation, ignorez cet e-mail.'
            );
    }
}
