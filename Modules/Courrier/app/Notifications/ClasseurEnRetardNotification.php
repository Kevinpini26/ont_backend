<?php

namespace Modules\Courrier\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Modules\Courrier\Models\Courrier;

/** Lot 6 : un courrier dépasse le délai du classeur d'attente (alerte à SEC1, relance unique de la DG). */
class ClasseurEnRetardNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly Courrier $courrier, private readonly string $destinataire) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $reference = $this->courrier->numero_enregistrement ?? "#{$this->courrier->id}";

        return [
            'type' => 'classeur_en_retard',
            'courrier_id' => $this->courrier->id,
            'objet' => $this->courrier->objet,
            'message' => $this->destinataire === 'dg'
                ? "Un courrier attend au classeur de SEC1 au-delà du délai : {$reference}. SEC1 décide de sa transmission."
                : "Ce courrier est au classeur au-delà du délai : {$reference}. À examiner, puis à transmettre à la DG si besoin.",
            'lien' => "/courriers/{$this->courrier->id}",
        ];
    }
}
