<?php

namespace Modules\Stagiaires\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Stagiaires\Models\StagiaireLienPublic;

class EngagementConfidentialiteASignerNotification extends Notification implements ShouldQueue
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
            ->subject('Engagement de confidentialité à signer — Office National du Tourisme')
            ->greeting("Bonjour {$stagiaire->nom},")
            ->line('Merci de consulter et signer votre engagement de confidentialité via le lien ci-dessous.')
            ->action("Consulter et signer l'engagement", $url)
            ->line('Ce lien est à usage unique et personnel.');
    }
}
