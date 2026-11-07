<?php

namespace Modules\Courrier\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Modules\Courrier\Models\DocumentProduitDirection;

/** Lot 6 : document transmis par le circuit direct (secrétariat destinataire ; copie pour information à la DG). */
class DocumentCircuitDirectNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly DocumentProduitDirection $document, private readonly string $destinataire) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'document_circuit_direct',
            'document_id' => $this->document->id,
            'courrier_id' => $this->document->courrier_id,
            'message' => $this->destinataire === 'copie_dg'
                ? 'Copie pour information : un document interne a été transmis directement entre directions.'
                : 'Un document interne vous est transmis directement : accusez-en réception.',
            'lien' => $this->destinataire === 'copie_dg' ? "/courriers/{$this->document->courrier_id}" : '/direction/documents-recus-direct',
        ];
    }
}
