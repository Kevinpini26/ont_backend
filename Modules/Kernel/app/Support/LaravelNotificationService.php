<?php

namespace Modules\Kernel\Support;

use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Modules\Kernel\Contracts\NotificationCanal;
use Modules\Kernel\Contracts\NotificationService;

/**
 * Implémentation actuelle : le système de mail/notification natif de
 * Laravel (façades `Mail` et `Notification`). Seul fichier du projet
 * autorisé à référencer ces façades — un remplacement futur (autre
 * fournisseur d'envoi, ajout d'un canal SMS...) se limite à réécrire cette
 * classe et son binding dans KernelServiceProvider, sans toucher aux
 * appelants.
 *
 * `Mail::queue()` (jamais `->send()`) : l'envoi ne doit jamais ralentir la
 * requête qui le déclenche — voir les appelants d'origine, qui portaient
 * chacun ce commentaire avant la centralisation ici.
 */
class LaravelNotificationService implements NotificationService
{
    public function __construct(private readonly NotificationCanal $smsCanal) {}

    public function envoyerMail(string $email, Mailable $mailable): void
    {
        Mail::to($email)->queue($mailable);
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
