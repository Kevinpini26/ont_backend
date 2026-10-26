<?php

namespace Modules\Courrier\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Modules\Courrier\Models\Courrier;

class CopieCourrierNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly Courrier $courrier) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'copie_courrier',
            'courrier_id' => $this->courrier->id,
            'objet' => $this->courrier->objet,
            'message' => "Copie pour information : {$this->courrier->objet}",
            'lien' => "/courriers/{$this->courrier->id}",
        ];
    }
}
