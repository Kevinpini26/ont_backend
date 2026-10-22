<?php

namespace Modules\Courrier\Services;

use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Models\TraitementDirection;
use Modules\Kernel\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Façade fine : la rédaction/validation (DocumentProduitRedactionService),
 * la consultation du PDF officiel (DocumentProduitPdfOfficielService) et le
 * circuit de réception (DocumentProduitReceptionService) vivent chacun dans
 * leur propre service — cette classe reste l'unique point d'entrée utilisé
 * par le contrôleur, pour qu'aucun appelant n'ait à connaître ce
 * découpage interne.
 */
class DocumentProduitDirectionService
{
    public function __construct(
        private readonly DocumentProduitRedactionService $redaction,
        private readonly DocumentProduitPdfOfficielService $pdfOfficiel,
        private readonly DocumentProduitReceptionService $reception,
    ) {}

    public function creer(TraitementDirection $traitement, User $secretariat, array $donnees): DocumentProduitDirection
    {
        return $this->redaction->creer($traitement, $secretariat, $donnees);
    }

    public function creerAutonome(User $secretariat, array $donnees): DocumentProduitDirection
    {
        return $this->redaction->creerAutonome($secretariat, $donnees);
    }

    public function modifierBrouillon(DocumentProduitDirection $document, User $secretariat, array $donnees): DocumentProduitDirection
    {
        return $this->redaction->modifierBrouillon($document, $secretariat, $donnees);
    }

    public function soumettre(DocumentProduitDirection $document, User $secretariat, array $donnees): DocumentProduitDirection
    {
        return $this->redaction->soumettre($document, $secretariat, $donnees);
    }

    public function demanderCorrection(DocumentProduitDirection $document, User $directeur, string $motif): DocumentProduitDirection
    {
        return $this->redaction->demanderCorrection($document, $directeur, $motif);
    }

    public function valider(DocumentProduitDirection $document, User $directeur): DocumentProduitDirection
    {
        return $this->redaction->valider($document, $directeur);
    }

    public function telechargerPdfOfficiel(DocumentProduitDirection $document): StreamedResponse
    {
        return $this->pdfOfficiel->telechargerPdfOfficiel($document);
    }

    public function telechargerPdfOfficielAccepteSec1(DocumentProduitDirection $document): StreamedResponse
    {
        return $this->pdfOfficiel->telechargerPdfOfficielAccepteSec1($document);
    }

    public function telechargerPdfOfficielEnAttenteDg(DocumentProduitDirection $document): StreamedResponse
    {
        return $this->pdfOfficiel->telechargerPdfOfficielEnAttenteDg($document);
    }

    public function transmettreReception(DocumentProduitDirection $document, User $secretariat): DocumentProduitDirection
    {
        return $this->reception->transmettreReception($document, $secretariat);
    }

    public function transmettreAutonomeReception(DocumentProduitDirection $document, User $secretariat): DocumentProduitDirection
    {
        return $this->reception->transmettreAutonomeReception($document, $secretariat);
    }

    public function accepterAutonomeReception(DocumentProduitDirection $document, User $agent): DocumentProduitDirection
    {
        return $this->reception->accepterAutonomeReception($document, $agent);
    }

    public function demanderCorrectionAutonomeReception(
        DocumentProduitDirection $document,
        User $agent,
        string $motif,
    ): DocumentProduitDirection {
        return $this->reception->demanderCorrectionAutonomeReception($document, $agent, $motif);
    }

    public function recevoir(DocumentProduitDirection $document, User $reception): DocumentProduitDirection
    {
        return $this->reception->recevoir($document, $reception);
    }
}
