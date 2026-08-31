<?php

namespace Modules\Kernel\Contracts;

use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Notifications\Notification;

/**
 * Point d'extension : envoie une notification (email ou autre canal futur)
 * à un destinataire. Couvre les trois mécanismes de dispatch utilisés dans
 * le projet — un Mailable mis en file directement par adresse, une
 * Notification portée par un modèle Notifiable, une Notification routée
 * vers une adresse sans compte associé (ex. un candidat externe). Aucun
 * code applicatif ne doit appeler `Mail::` ou `->notify()` directement, tout
 * passe par ce contrat, pour que remplacer le mécanisme sous-jacent (ou
 * ajouter un canal, SMS par exemple) reste localisé à la seule
 * implémentation liée dans Modules\Kernel\Providers\KernelServiceProvider.
 * Les classes Mailable/Notification elles-mêmes (contenu du message) ne
 * sont pas concernées par cette encapsulation : seul le mécanisme d'envoi
 * l'est.
 */
interface NotificationService
{
    /**
     * Met en file un Mailable à destination d'une adresse email brute (pas
     * de compte utilisateur associé, ex. accusé de réception à un candidat
     * externe).
     */
    public function envoyerMail(string $email, Mailable $mailable): void;

    /**
     * Envoie une Notification à un modèle Notifiable (ex. un utilisateur du
     * système, via ses canaux configurés).
     */
    public function notifier(mixed $notifiable, Notification $notification): void;

    /**
     * Envoie une Notification à une adresse email sans compte utilisateur
     * associé (destinataire "à la demande").
     */
    public function notifierParEmail(string $email, Notification $notification): void;

    /**
     * Envoie un message texte court par SMS — complément du courriel pour
     * un contact qui n'a renseigné qu'un numéro de téléphone (voir
     * Modules\Kernel\Support\SmsNotificationCanal).
     */
    public function notifierParSms(string $numero, string $message): void;
}
