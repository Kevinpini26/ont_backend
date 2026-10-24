<?php

namespace Modules\Kernel\Support;

use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Modules\Kernel\Contracts\NotificationCanal;
use Modules\Kernel\Contracts\NotificationService;
use Modules\Kernel\Enums\StatutNotificationEmail;
use Modules\Kernel\Jobs\EnvoyerNotificationEmailJob;
use Modules\Kernel\Models\NotificationEmail;

/**
 * Implémentation actuelle : le système de mail/notification natif de
 * Laravel (façade `Notification`, et Modules\Kernel\Jobs\EnvoyerNotificationEmailJob
 * pour l'envoi effectif d'un Mailable). Avec LaravelNotificationService et
 * ce job, seuls fichiers du projet autorisés à référencer les façades
 * Mail/Notification — un remplacement futur (autre fournisseur d'envoi,
 * ajout d'un canal SMS...) se limite à réécrire ces classes et leur
 * binding dans KernelServiceProvider, sans toucher aux appelants.
 *
 * `envoyerMail()` ne touche jamais `Mail::` lui-même : il crée la ligne de
 * suivi (table `notifications_email`) puis met en file le job qui enverra
 * réellement le message et tiendra cette ligne à jour (tentatives, statut,
 * dernière erreur) — l'envoi ne doit jamais ralentir la requête qui le
 * déclenche.
 */
class LaravelNotificationService implements NotificationService
{
    public function __construct(private readonly NotificationCanal $smsCanal) {}

    public function envoyerMail(string $email, Mailable $mailable, string $type, ?Model $sujet = null, array $alerterSiEchec = []): void
    {
        $notification = NotificationEmail::query()->create([
            'type' => $type,
            'destinataire' => $email,
            'notifiable_type' => $sujet?->getMorphClass(),
            'notifiable_id' => $sujet?->getKey(),
            'statut' => StatutNotificationEmail::EN_FILE,
        ]);

        EnvoyerNotificationEmailJob::dispatch($notification->id, $mailable, $email, $alerterSiEchec);
    }

    public function notifier(mixed $notifiable, Notification $notification): void
    {
        $notifiable->notify($notification);
    }

    public function notifierParEmail(string $email, Notification $notification): void
    {
        NotificationFacade::route('mail', $email)->notify($notification);
    }

    public function notifierParSms(string $numero, string $message): void
    {
        $this->smsCanal->envoyer($numero, $message);
    }
}
