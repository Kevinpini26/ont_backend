<?php

namespace Modules\Courrier\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Modules\Courrier\Models\Courrier;

class CourrierDgNotification extends Notification
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
            'type' => 'courrier_dg',
            'evenement' => $this->evenement,
            'courrier_id' => $this->courrier->id,
            'objet' => $this->courrier->objet,
            'message' => match ($this->evenement) {
                'confie_au_dga' => "La Direction Générale vous confie un courrier pour instruction : {$this->courrier->objet}",
                'retour_dga' => "Le DGA a retourné sa recommandation sur : {$this->courrier->objet}",
                default => "Un courrier attend votre décision : {$this->courrier->objet}",
            },
            'lien' => "/courriers/{$this->courrier->id}",
        ];
    }
}
