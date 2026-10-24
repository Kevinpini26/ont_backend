<?php

namespace Modules\Courrier\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Models\Setting;

class ReponseFinaleCourrierExterneMail extends Mailable
{
    public function __construct(
        public readonly ?Courrier $courrierOrigine,
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

    /**
     * Le PDF officiel est joint en complément du lien signé, jamais à sa
     * place : toute anomalie détectée ici (fichier absent, altéré, ou
     * trop volumineux) fait simplement partir l'email sans pièce jointe,
     * elle ne bloque jamais l'envoi puisque le lien reste disponible.
     */
    public function attachments(): array
    {
        $chemin = $this->reponse->pdf_chemin;
        $sha256 = $this->reponse->pdf_sha256;

        if (blank($chemin) || blank($sha256) || ! Storage::disk('local')->exists($chemin)) {
            return [];
        }

        $limiteOctets = (int) Setting::valeur('courrier.piece_jointe_limite_octets', 10 * 1024 * 1024);
        if (Storage::disk('local')->size($chemin) > $limiteOctets) {
            Log::warning('PDF officiel de réponse non joint : taille au-delà de la limite configurée.', [
                'courrier_id' => $this->reponse->id,
                'numero_depart' => $this->reponse->numero_depart,
                'taille_octets' => Storage::disk('local')->size($chemin),
                'limite_octets' => $limiteOctets,
            ]);

            return [];
        }

        $contenu = Storage::disk('local')->get($chemin);
        if (! hash_equals($sha256, hash('sha256', $contenu))) {
            Log::warning('PDF officiel de réponse non joint : empreinte SHA-256 ne correspond plus.', [
                'courrier_id' => $this->reponse->id,
                'numero_depart' => $this->reponse->numero_depart,
            ]);

            return [];
        }

        return [
            Attachment::fromData(fn () => $contenu, "ONT-Reponse-{$this->reponse->numero_depart}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
