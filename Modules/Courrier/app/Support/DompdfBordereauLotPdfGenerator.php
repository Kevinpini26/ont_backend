<?php

namespace Modules\Courrier\Support;

use Modules\Courrier\Contracts\BordereauLotPdfGenerator;
use Modules\Courrier\Enums\NiveauConfidentialite;
use Modules\Courrier\Models\BordereauLot;
use Modules\Courrier\Services\CourrierAnnotationService;
use Modules\Kernel\Contracts\PdfGenerationService;

class DompdfBordereauLotPdfGenerator implements BordereauLotPdfGenerator
{
    public function __construct(
        private readonly PdfGenerationService $pdf,
        private readonly CourrierAnnotationService $notes,
    ) {}

    public function generer(BordereauLot $bordereau): string
    {
        $bordereau->loadMissing(['emetteur', 'courriers']);

        // Notes du circuit de chaque dossier ; le fond d'un courrier confidentiel n'est pas reproduit sur le bordereau.
        $notes = [];
        $notesMasquees = [];
        foreach ($bordereau->courriers as $courrier) {
            if ($courrier->niveau_confidentialite !== NiveauConfidentialite::ORDINAIRE) {
                $notesMasquees[$courrier->id] = $courrier->annotations()->exists();

                continue;
            }
            $notes[$courrier->id] = $this->notes->notesImprimables($courrier);
        }

        return $this->pdf->genererDepuisVue('courrier::bordereau-lot', [
            'bordereau' => $bordereau,
            'notes' => $notes,
            'notesMasquees' => $notesMasquees,
        ]);
    }
}
