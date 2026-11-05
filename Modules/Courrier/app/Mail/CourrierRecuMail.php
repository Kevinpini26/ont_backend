<?php

namespace Modules\Courrier\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Modules\Courrier\Models\Courrier;

/**
 * Envoyée à la direction destinataire d'un nouveau courrier. Toujours mise
 * en file via Modules\Kernel\Contracts\NotificationService::envoyerMail()
 * (jamais Mail:: directement) : voir CourrierReceptionService::creer().
 */
class CourrierRecuMail extends Mailable
{
    public function __construct(public readonly Courrier $courrier) {}

    public function envelope(): Envelope
    {
        $reference = $this->courrier->numero_accuse_reception ?? $this->courrier->numero_enregistrement;

        return new Envelope(
            subject: "Nouveau courrier reçu — {$reference}",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'courrier::emails.recu');
    }
}
