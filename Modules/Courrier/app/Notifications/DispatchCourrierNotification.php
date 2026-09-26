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
        ];
    }
}
