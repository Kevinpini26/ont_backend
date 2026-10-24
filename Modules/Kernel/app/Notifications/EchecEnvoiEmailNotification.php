<?php

namespace Modules\Kernel\Notifications;

use Illuminate\Notifications\Notification;
use Modules\Kernel\Models\NotificationEmail;

/**
 * Alerte dans la cloche de notifications internes d'un agent, quand un
 * e-mail externe qu'il attend (ou dont il est responsable) a
 * définitivement échoué après ses tentatives — voir
 * Modules\Kernel\Jobs\EnvoyerNotificationEmailJob::failed(). Ne bloque
 * jamais le circuit : l'agent reste libre d'agir autrement (téléphone,
 * remise en main propre) pendant que la cause de l'échec est corrigée.
 */
class EchecEnvoiEmailNotification extends Notification
{
    public function __construct(public readonly NotificationEmail $notificationEmail) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'notification_email_echec',
            'notification_email_id' => $this->notificationEmail->id,
            'notification_type' => $this->notificationEmail->type,
            'message' => "Échec d'envoi de l'e-mail \"{$this->notificationEmail->type}\" vers {$this->notificationEmail->destinataire}.",
        ];
    }
}
