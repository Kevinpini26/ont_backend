<?php

namespace Modules\Courrier\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Modules\Courrier\Models\DispatchCourrier;

class DispatchCourrierNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly DispatchCourrier $dispatch, private readonly string $evenement) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'dispatch_courrier',
            'evenement' => $this->evenement,
            'dispatch_id' => $this->dispatch->id,
            'courrier_id' => $this->dispatch->courrier_id,
            'direction_id' => $this->dispatch->direction_id,
            'message' => match ($this->evenement) {
                'recu_direction' => 'Un courrier a été transmis à votre direction par le Secrétariat 02.',
                'relance_direction' => 'Rappel : un courrier transmis à votre direction attend toujours son traitement.',
                'relance_direction_dg' => 'Rappel : un courrier transmis à une direction attend toujours son traitement.',
                default => 'Un courrier est à envoyer à sa destination (dispatch).',
            },
            'lien' => "/courriers/{$this->dispatch->courrier_id}",
        ];
    }
}
