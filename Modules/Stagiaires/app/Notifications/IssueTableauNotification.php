<?php

namespace Modules\Stagiaires\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Stagiaires\Contracts\NotificationAvecSms;
use Modules\Stagiaires\Models\Stagiaire;

/**
 * Lot B : diffusion de l'issue (retenu/non retenu) au candidat, une fois
 * le tableau approuvé — jamais avant (voir StagiaireStatut::EN_INSTRUCTION).
 */
class IssueTableauNotification extends Notification implements NotificationAvecSms, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Stagiaire $stagiaire, public readonly bool $retenu) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function messageSms(): string
    {
        return $this->retenu
            ? 'ONT : votre demande de stage a été retenue. Vous serez prochainement contacté(e) pour les modalités.'
            : "ONT : votre demande de stage n'a pas été retenue. {$this->motifLabel()}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->greeting("Bonjour {$this->stagiaire->nom},");

        if ($this->retenu) {
            return $mail
                ->subject('Votre demande de stage a été retenue — Office National du Tourisme')
                ->line('Votre demande de stage a été retenue par la Direction Générale.')
                ->line("Direction d'accueil : {$this->stagiaire->direction?->nom}.")
                ->line('Vous serez prochainement contacté(e) pour les modalités pratiques de votre arrivée.');
        }

        return $mail
            ->subject('Votre demande de stage — Office National du Tourisme')
            ->line("Nous vous remercions pour l'intérêt porté à l'Office National du Tourisme.")
            ->line("Après examen, votre demande de stage n'a malheureusement pas pu être retenue.")
            ->line("Motif : {$this->motifLabel()}")
            ->when($this->stagiaire->motif_non_retenu_libre, fn ($m) => $m->line($this->stagiaire->motif_non_retenu_libre));
    }

    private function motifLabel(): string
    {
        return config('stagiaires.motifs_non_retenu')[$this->stagiaire->motif_non_retenu] ?? (string) $this->stagiaire->motif_non_retenu;
    }
}
