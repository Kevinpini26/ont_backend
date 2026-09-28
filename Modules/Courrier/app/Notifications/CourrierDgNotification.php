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
        ];
    }
}
