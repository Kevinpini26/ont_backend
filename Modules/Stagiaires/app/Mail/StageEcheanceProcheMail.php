<?php

namespace Modules\Stagiaires\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Modules\Stagiaires\Models\Stagiaire;

class StageEcheanceProcheMail extends Mailable
{
    public function __construct(public readonly Stagiaire $stagiaire) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Échéance de stage proche — {$this->stagiaire->nom}",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'stagiaires::emails.echeance-proche');
    }
}
