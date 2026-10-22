<?php

namespace Modules\Courrier\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DispatchStatut;
use Modules\Courrier\Enums\DispatchTypeDestination;
use Modules\Courrier\Enums\DocumentProduitOrigine;
use Modules\Courrier\Enums\DocumentProduitStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Kernel\Enums\Poste;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Consultation du PDF officiel d'un document produit par une direction —
 * trois points d'accès selon l'étape du circuit (producteur une fois
 * validé, SEC1 une fois accepté par la Réception, DG une fois en attente
 * d'avis), chacun avec sa propre garde structurelle complète plutôt qu'un
 * simple contrôle de poste, voir CourrierController::telechargerNumerisation()
 * pour le même principe côté numérisation.
 */
class DocumentProduitPdfOfficielService
{
    public function telechargerPdfOfficiel(DocumentProduitDirection $document): StreamedResponse
    {
        if ($document->origine !== DocumentProduitOrigine::AUTONOME
            || $document->statut !== DocumentProduitStatut::VALIDE
            || $document->pdf_officiel_version_contenu !== $document->version_contenu) {
            abort(404);
        }

        return $this->streamerPdfOfficiel($document);
    }

    public function telechargerPdfOfficielAccepteSec1(DocumentProduitDirection $document): StreamedResponse
    {
        $document->refresh();
        $courrier = $document->courrier_id === null
            ? null
            : Courrier::withoutGlobalScopes()->find($document->courrier_id);

        if ($document->origine !== DocumentProduitOrigine::AUTONOME
            || $document->statut !== DocumentProduitStatut::ENTRE_CIRCUIT
            || $document->transmis_reception_par_id === null
            || $document->transmis_reception_at === null
            || $document->recu_par_id === null
            || $document->recu_at === null
            || $courrier === null
            || $courrier->direction_origine_id !== $document->direction_id
            || ! in_array($courrier->statut, [CourrierStatut::RECU, CourrierStatut::EN_ATTENTE_AVIS_DG], true)
            || $courrier->reference_documentaire === null
            || preg_match('/\A\d{3,}\/[A-Z]{1,12}\/[A-Z0-9]{1,20}\/ONT\/\d{4}\z/', $courrier->reference_documentaire) !== 1
            || $courrier->numero_enregistrement === null
            || trim($courrier->numero_enregistrement) === ''
            || ! $courrier->transitions()->where('nouveau_statut', CourrierStatut::RECU->value)
                ->where('destinataire_poste', Poste::SECRETARIAT_1->value)
                ->where('changed_by_id', $document->recu_par_id)
                ->exists()) {
            abort(404);
        }

        return $this->streamerPdfOfficiel($document);
    }

    public function telechargerPdfOfficielEnAttenteDg(DocumentProduitDirection $document): StreamedResponse
    {
        $document->refresh();
        $courrier = $document->courrier_id === null
            ? null
            : Courrier::withoutGlobalScopes()->find($document->courrier_id);
        $dispatch = $courrier?->dispatchs()->first();

        if ($document->origine !== DocumentProduitOrigine::AUTONOME
            || $document->statut !== DocumentProduitStatut::ENTRE_CIRCUIT
            || $document->transmis_reception_par_id === null
            || $document->transmis_reception_at === null
            || $document->recu_par_id === null
            || $document->recu_at === null
            || $courrier === null
            || $courrier->direction_origine_id !== $document->direction_id
            || ! in_array($courrier->statut, [CourrierStatut::EN_ATTENTE_AVIS_DG, CourrierStatut::EN_DISPATCH], true)
            || $courrier->reference_documentaire === null
            || preg_match('/\A\d{3,}\/[A-Z]{1,12}\/[A-Z0-9]{1,20}\/ONT\/\d{4}\z/', $courrier->reference_documentaire) !== 1
            || $courrier->numero_enregistrement === null
            || trim($courrier->numero_enregistrement) === ''
            || ! $courrier->transitions()->where('nouveau_statut', CourrierStatut::EN_ATTENTE_AVIS_DG->value)
                ->where('destinataire_poste', Poste::DG->value)
                ->exists()
            || ($dispatch === null
                ? $courrier->statut !== CourrierStatut::EN_ATTENTE_AVIS_DG
                : $courrier->statut !== CourrierStatut::EN_DISPATCH
                    || $dispatch->statut !== DispatchStatut::EN_ATTENTE
                    || $dispatch->type_destination !== DispatchTypeDestination::DIRECTION
                    || $dispatch->dossier_id !== null
                    || $dispatch->direction_id !== $courrier->direction_destination_id
                    || $courrier->dispatchs()->count() !== 1)) {
            abort(404);
        }

        return $this->streamerPdfOfficiel($document);
    }

    private function streamerPdfOfficiel(DocumentProduitDirection $document): StreamedResponse
    {
        $chemin = $document->pdf_officiel_chemin;
        $sha256 = $document->pdf_officiel_sha256;
        $prefixeAttendu = "documents-internes/officiels/{$document->id}/";

        if ($document->origine !== DocumentProduitOrigine::AUTONOME
            || $document->pdf_officiel_version_contenu !== $document->version_contenu
            || ! is_string($chemin)
            || ! str_starts_with($chemin, $prefixeAttendu)
            || str_contains($chemin, '..')
            || ! is_string($sha256)
            || preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1
            || ! Storage::disk('local')->exists($chemin)) {
            abort(404);
        }

        $contenu = Storage::disk('local')->get($chemin);
        if (! is_string($contenu)
            || ! hash_equals($sha256, hash('sha256', $contenu))
            || ! str_starts_with($contenu, '%PDF-')) {
            Log::critical('Intégrité du PDF officiel de document autonome compromise.', ['document_id' => $document->id]);
            abort(404);
        }

        return response()->streamDownload(
            static function () use ($contenu): void {
                echo $contenu;
            },
            "document-interne-{$document->id}.pdf",
            ['Content-Type' => 'application/pdf'],
        );
    }
}
