<?php

namespace Modules\Stagiaires\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Stagiaires\Models\StagiaireLienPublic;

class RapportStageDemandeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly StagiaireLienPublic $lien) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $stagiaire = $this->lien->stagiaire;
        $url = rtrim(config('app.frontend_url'), '/')."/liens/{$this->lien->token}";

        return (new MailMessage)
            ->subject('Dépôt de votre rapport de fin de stage — Office National du Tourisme')
            ->greeting("Bonjour {$stagiaire->nom},")
            ->line('Votre stage touche à sa fin. Merci de déposer votre rapport de fin de stage via le lien ci-dessous.')
            ->action('Déposer mon rapport', $url)
            ->line('Ce lien est à usage unique et personnel.');
    }
}
