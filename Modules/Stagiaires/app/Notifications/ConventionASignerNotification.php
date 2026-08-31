<?php

namespace Modules\Stagiaires\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Stagiaires\Contracts\NotificationAvecSms;
use Modules\Stagiaires\Models\StagiaireLienPublic;

/**
 * Envoyée au stagiaire (pas un utilisateur du système, d'où le canal mail
 * en routage à la demande — voir StagiaireCircuitService::validerArrivee)
 * pour l'inviter à signer sa convention de stage via un lien à usage unique.
 * ShouldQueue : l'envoi ne doit jamais ralentir la réponse HTTP.
 */
class ConventionASignerNotification extends Notification implements NotificationAvecSms, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly StagiaireLienPublic $lien) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function messageSms(): string
    {
        $url = rtrim(config('app.frontend_url'), '/')."/liens/{$this->lien->token}";

        return "ONT : votre convention de stage est prête à être signée. {$url}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        $stagiaire = $this->lien->stagiaire;
        $url = rtrim(config('app.frontend_url'), '/')."/liens/{$this->lien->token}";

        return (new MailMessage)
            ->subject('Convention de stage à signer — Office National du Tourisme')
            ->greeting("Bonjour {$stagiaire->nom},")
            ->line("Votre stage au sein de la direction {$stagiaire->direction?->nom} a été validé.")
            ->line('Merci de consulter et signer votre convention de stage via le lien ci-dessous.')
            ->action('Consulter et signer la convention', $url)
            ->line('Ce lien est à usage unique et personnel.');
    }
}
