<?php

namespace Modules\Kernel\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Déclenchée uniquement par SupervisionController::envoyerEmailTest() :
 * preuve de bon fonctionnement de bout en bout (SMTP, file, worker) pour
 * l'administrateur qui en fait la demande, jamais automatique.
 */
class EmailTestSupervisionMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Email de test — Santé du système ONT');
    }

    public function content(): Content
    {
        return new Content(markdown: 'kernel::emails.test-supervision');
    }
}
