<?php

namespace Modules\Courrier\Support;

use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Contracts\CourrierPdfGenerator;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Contracts\PdfGenerationService;
use RuntimeException;

class DompdfCourrierPdfGenerator implements CourrierPdfGenerator
{
    public function __construct(
        private readonly TipTapHtmlRenderer $rendu,
        private readonly PdfGenerationService $pdf,
    ) {}

    public function generer(Courrier $courrier): string
    {
        $contenu = $this->pdf->genererDepuisVue('courrier::courrier-signe', [
            'courrier' => $courrier->loadMissing('signataire'),
            'corpsHtml' => $this->rendu->render($courrier->projet_reponse_contenu),
            'logoOntDataUri' => $this->logoOntDataUri(),
            'dateOfficielle' => $courrier->signe_at?->copy()->locale('fr')->translatedFormat('d F Y') ?? '',
            'referenceNref' => filled($courrier->reference_documentaire)
                ? $courrier->reference_documentaire
                : $courrier->numero_depart,
        ]);

        $chemin = "courriers-signes/courrier-{$courrier->id}.pdf";

        Storage::disk('local')->put($chemin, $contenu);

        return $chemin;
    }

    public function genererPourSignature(Courrier $courrier, string $sourceAutorite): string
    {
        $chemin = "courriers-a-signer/courrier-{$courrier->id}-{$courrier->numero_depart}.pdf";
        $this->genererPourSignatureDans($courrier, $sourceAutorite, $chemin);

        return $chemin;
    }

    public function genererPourSignatureDans(Courrier $courrier, string $sourceAutorite, string $chemin): void
    {
        $contenu = $this->pdf->genererDepuisVue('courrier::courrier-a-signer', [
            'courrier' => $courrier->loadMissing('valideSignaturePar'),
            'corpsHtml' => $this->rendu->render($courrier->projet_reponse_contenu),
            'sourceAutorite' => $sourceAutorite,
            'logoOntDataUri' => $this->logoOntDataUri(),
            'dateOfficielle' => $courrier->valide_signature_at?->copy()->locale('fr')->translatedFormat('d F Y') ?? '',
            'referenceNref' => filled($courrier->reference_documentaire)
                ? $courrier->reference_documentaire
                : $courrier->numero_depart,
        ]);

        if (! Storage::disk('local')->put($chemin, $contenu)) {
            throw new RuntimeException("Impossible d'écrire le PDF pré-signature à l'emplacement temporaire.");
        }
    }

    private function logoOntDataUri(): string
    {
        $logoPath = base_path('Modules/Courrier/resources/ONT.png');

        if (! is_file($logoPath)) {
            return '';
        }

        return 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath));
    }
}
