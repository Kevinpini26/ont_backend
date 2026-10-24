<?php

namespace Modules\Courrier\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Modules\Courrier\Models\Courrier;

/**
 * Envoyée au partenaire externe dès le dépôt technique de son courrier en
 * ligne (module Public), avant son enregistrement administratif par la
 * Réception — même gabarit fixe que
 * AccuseReceptionCandidatMail, vocabulaire adapté. Toujours mise en file
 * via Modules\Kernel\Contracts\NotificationService::envoyerMail() (jamais
 * Mail:: directement) : voir
 * CourrierReceptionService::creerCourrierExterneDepuisPublic().
 */
class AccuseReceptionCourrierExterneMail extends Mailable
{
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
