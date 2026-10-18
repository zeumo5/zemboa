<?php
namespace App\Notifications;


use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OwnerInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly string $storeName
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('owner.invitation.form', [
            'token' => $this->token,
            'email' => $notifiable->email,
        ]);

        return (new MailMessage)
            ->subject('ZEMBOA - Invitation propriétaire')
            ->greeting('Bienvenue sur ZEMBOA !')
            ->line(
                'Vous avez été invité à gérer la boutique : '
                . $this->storeName
            )
            ->line(
                'Définissez votre mot de passe pour activer votre compte.'
            )
            ->action('Activer mon compte', $url)
            ->line('Ce lien expire après 60 minutes.')
            ->line(
                'Votre boutique peut rester en attente de validation administrative.'
            );
    }
}
