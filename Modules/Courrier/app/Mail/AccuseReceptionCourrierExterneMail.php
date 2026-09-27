<?php

namespace Modules\Courrier\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Courrier\Models\Courrier;

/**
 * Envoyée au partenaire externe dès le dépôt technique de son courrier en
 * ligne (module Public), avant son enregistrement administratif par la
 * Réception — même gabarit fixe que
 * AccuseReceptionCandidatMail, vocabulaire adapté. Toujours dispatchée via
 * Mail::queue() (jamais ->send()) : voir
 * CourrierCircuitService::creerCourrierExterneDepuisPublic().
 */
class AccuseReceptionCourrierExterneMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Courrier $courrier) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Accusé de réception de votre courrier — ONT',
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'courrier::emails.accuse-reception-courrier-externe');
    }
}
