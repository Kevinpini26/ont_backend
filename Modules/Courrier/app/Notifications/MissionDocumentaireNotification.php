<?php

namespace Modules\Courrier\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Modules\Courrier\Models\MissionDocumentaire;

class MissionDocumentaireNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly MissionDocumentaire $mission,
        private readonly string $evenement,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'mission_documentaire',
            'evenement' => $this->evenement,
            'mission_id' => $this->mission->id,
            'courrier_id' => $this->mission->courrier_id,
            'objet' => $this->mission->courrier?->objet,
            'message' => match ($this->evenement) {
                'retournee' => "Une mission de rédaction vous est retournée : {$this->mission->courrier?->objet}",
                default => "Une mission de rédaction vous est assignée : {$this->mission->courrier?->objet}",
            },
            'lien' => "/courriers/{$this->mission->courrier_id}",
        ];
    }
}
