<?php

namespace Modules\Courrier\Support;

use Modules\Courrier\Contracts\BordereauLotPdfGenerator;
use Modules\Courrier\Models\BordereauLot;
use Modules\Kernel\Contracts\PdfGenerationService;

class DompdfBordereauLotPdfGenerator implements BordereauLotPdfGenerator
{
    public function __construct(private readonly PdfGenerationService $pdf) {}

    public function generer(BordereauLot $bordereau): string
    {
        $bordereau->loadMissing(['emetteur', 'courriers']);

        return $this->pdf->genererDepuisVue('courrier::bordereau-lot', [
            'bordereau' => $bordereau,
        ]);
    }
}
