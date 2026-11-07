<?php

namespace Modules\Courrier\Support;

use Modules\Courrier\Contracts\AccuseReceptionGuichetGenerator;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Contracts\PdfGenerationService;
use Modules\Kernel\Models\Setting;

class DompdfAccuseReceptionGuichetGenerator implements AccuseReceptionGuichetGenerator
{
    public function __construct(private readonly PdfGenerationService $pdf) {}

    public function generer(Courrier $courrier): string
    {
        $estDemandeStage = $courrier->type === CourrierType::DEMANDE_STAGE;

        return $this->pdf->genererDepuisVue('courrier::accuse-reception-guichet', [
            'courrier' => $courrier,
            // Lot 6 : pièce principale comprise (elle est aussi la première annexe enregistrée).
            'nombrePieces' => max($courrier->piecesJointes()->count(), $courrier->piece_jointe_chemin ? 1 : 0),
            'deposeLe' => $courrier->created_at->copy()->setTimezone((string) Setting::valeur('calendrier.fuseau', config('kernel.calendrier.fuseau', 'Africa/Kinshasa'))),
            'lienSuivi' => rtrim((string) config('app.frontend_url'), '/').'/suivi-dossier',
            'nomDeposant' => [
                'libelle' => $estDemandeStage ? 'Candidat' : 'Expéditeur',
                'valeur' => $estDemandeStage
                    ? ($courrier->candidat_nom ?? '—')
                    : ($courrier->expediteur_externe_nom ?? '—'),
            ],
        ]);
    }
}
