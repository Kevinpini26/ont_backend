<?php

namespace Modules\Courrier\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Modules\Courrier\Models\Courrier;

class ProjetReponseRelectureNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly Courrier $courrier, private readonly string $evenement) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'projet_reponse_relecture',
            'evenement' => $this->evenement,
            'message' => match ($this->evenement) {
                'retourne_pour_correction' => 'Votre projet de réponse a été retourné pour correction.',
                'resoumis' => 'Un projet de réponse corrigé vous a été resoumis pour relecture.',
                default => 'Un projet de réponse vous a été soumis pour relecture.',
            },
            'courrier_id' => $this->courrier->id,
            'objet' => $this->courrier->objet,
            'lien' => "/courriers/{$this->courrier->id}",
        ];
    }
}
