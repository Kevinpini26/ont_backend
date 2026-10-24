<?php

namespace Modules\Courrier\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Modules\Courrier\Models\Courrier;

/**
 * Envoyée au candidat dès l'enregistrement de sa demande de stage déposée
 * en ligne (module Public) — jamais de contenu généré à la volée : gabarit
 * fixe, seul le nom et le numéro d'accusé de réception varient. Toujours
 * mise en file via
 * Modules\Kernel\Contracts\NotificationService::envoyerMail() (jamais
 * Mail:: directement) : voir CourrierReceptionService::creerDepuisPublic().
 */
class AccuseReceptionCandidatMail extends Mailable
{
    public function __construct(public readonly Courrier $courrier) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Accusé de réception de votre demande de stage — ONT',
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'courrier::emails.accuse-reception-candidat');
    }
}
