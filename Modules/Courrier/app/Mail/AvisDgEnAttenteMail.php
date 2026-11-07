<?php

namespace Modules\Courrier\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Modules\Courrier\Models\Courrier;

class AvisDgEnAttenteMail extends Mailable
{
    public function __construct(public readonly Courrier $courrier) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Avis en attente au-delà du délai — {$this->courrier->numeroSuivi()}",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'courrier::emails.avis-dg-en-attente');
    }
}
