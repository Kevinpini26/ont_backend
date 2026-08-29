<?php

namespace Modules\Kernel\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Kernel\Models\User;

/**
 * Remplace la notification ResetPassword par défaut de Laravel (voir
 * User::sendPasswordResetNotification()) : celle-ci pointe vers une route
 * web nommée "password.reset" qui n'existe pas dans cette API — le lien
 * doit mener à la page de réinitialisation du frontend React, pas à une
 * vue Blade.
 */
class ReinitialisationMotDePasseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = rtrim(config('app.frontend_url'), '/').'/reinitialiser-mot-de-passe?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->email,
        ]);

        return (new MailMessage)
            ->subject('Réinitialisation de votre mot de passe — ONT')
            ->greeting("Bonjour {$notifiable->name},")
            ->line('Vous avez demandé la réinitialisation de votre mot de passe sur le système d\'information de l\'ONT.')
            ->action('Réinitialiser mon mot de passe', $url)
            ->line('Ce lien expire dans '.config('auth.passwords.users.expire').' minutes.')
            ->line('Si vous n\'êtes pas à l\'origine de cette demande, ignorez simplement cet e-mail : votre mot de passe actuel reste inchangé.');
    }
}
