<?php

namespace Modules\Courrier\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Modules\Courrier\Models\CourrierAnnotation;

/** Notification interne (jamais d'e-mail) : une instruction ou une note urgente vise le destinataire. */
class AnnotationCircuitNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly CourrierAnnotation $annotation) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $courrier = $this->annotation->courrier;
        $nature = $this->annotation->type->value === 'instruction' ? 'Nouvelle instruction' : 'Note urgente';

        return [
            'type' => 'annotation_circuit',
            'annotation_id' => $this->annotation->id,
            'courrier_id' => $this->annotation->courrier_id,
            'objet' => $courrier?->objet,
            'message' => "{$nature} sur le courrier : ".($courrier?->objet ?? ''),
            'lien' => "/courriers/{$this->annotation->courrier_id}",
        ];
    }
}
