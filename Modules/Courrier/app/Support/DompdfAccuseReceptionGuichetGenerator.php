<?php

namespace Modules\Courrier\Support;

use Modules\Courrier\Contracts\AccuseReceptionGuichetGenerator;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Contracts\PdfGenerationService;

class DompdfAccuseReceptionGuichetGenerator implements AccuseReceptionGuichetGenerator
{
    public function __construct(private readonly PdfGenerationService $pdf) {}

    public function generer(Courrier $courrier): string
    {
        $estDemandeStage = $courrier->type === CourrierType::DEMANDE_STAGE;

        return $this->pdf->genererDepuisVue('courrier::accuse-reception-guichet', [
            'courrier' => $courrier,
            'nomDeposant' => [
                'libelle' => $estDemandeStage ? 'Candidat' : 'Expéditeur',
                'valeur' => $estDemandeStage
                    ? ($courrier->candidat_nom ?? '—')
                    : ($courrier->expediteur_externe_nom ?? '—'),
            ],
        ]);
    }
}
