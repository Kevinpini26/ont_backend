<?php

namespace Modules\Courrier\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Modules\Courrier\Models\TraitementDirection;

class TraitementDirectionNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly TraitementDirection $traitement, private readonly string $evenement) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'traitement_direction', 'evenement' => $this->evenement,
            'traitement_id' => $this->traitement->id, 'courrier_id' => $this->traitement->courrier_id,
            'direction_id' => $this->traitement->direction_id,
        ];
    }
}
