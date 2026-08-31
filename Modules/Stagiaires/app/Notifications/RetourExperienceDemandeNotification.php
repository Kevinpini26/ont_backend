<?php

namespace Modules\Stagiaires\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Stagiaires\Contracts\NotificationAvecSms;
use Modules\Stagiaires\Models\StagiaireLienPublic;

// ShouldQueue : l'envoi ne doit jamais ralentir la réponse HTTP.
class RetourExperienceDemandeNotification extends Notification implements NotificationAvecSms, ShouldQueue
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

        return "ONT : donnez votre avis sur votre stage (confidentiel). {$url}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        $stagiaire = $this->lien->stagiaire;
        $url = rtrim(config('app.frontend_url'), '/')."/liens/{$this->lien->token}";

        return (new MailMessage)
            ->subject('Votre avis sur votre stage — Office National du Tourisme')
            ->greeting("Bonjour {$stagiaire->nom},")
            ->line('Votre stage est à présent clôturé. Nous aimerions recueillir votre avis sur votre expérience.')
            ->line("Ce court formulaire (encadrement, missions, ambiance) est confidentiel : il n'est jamais communiqué à votre direction d'accueil.")
            ->action('Donner mon avis', $url)
            ->line('Ce lien est à usage unique et personnel.');
    }
}
