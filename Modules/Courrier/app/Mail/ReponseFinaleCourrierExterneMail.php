<?php

namespace Modules\Courrier\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Courrier\Models\Courrier;

class ReponseFinaleCourrierExterneMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Courrier $courrierOrigine,
        public readonly Courrier $reponse,
        public readonly string $urlTelechargement,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Réponse de l’Office National du Tourisme à votre courrier');
    }

    public function content(): Content
    {
        return new Content(markdown: 'courrier::emails.reponse-finale-courrier-externe');
    }
}
