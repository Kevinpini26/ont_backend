<?php

namespace Modules\Courrier\Console;

use Illuminate\Console\Command;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Services\AnonymiserCandidatureService;

/**
 * Politique de conservation des données : une candidature de stage non
 * retenue (avis DG défavorable) n'a plus de raison de conserver les
 * données personnelles du candidat au-delà d'un délai raisonnable — seules
 * les données nécessaires aux statistiques (type, statut, avis, direction,
 * dates, volumes) sont conservées indéfiniment.
 *
 * Le délai court à partir de la décision du DG (`avis_dg_rendu_at`), pas de
 * la réception du courrier : c'est la date à laquelle le dossier devient
 * effectivement « une candidature non retenue ».
 */
class AnonymiserCandidaturesNonRetenuesCommand extends Command
{
    protected $signature = 'courrier:anonymiser-candidatures-non-retenues {--seuil-mois=12}';

    protected $description = 'Anonymise les candidatures de stage à avis DG défavorable, passé le délai de conservation';

    public function handle(AnonymiserCandidatureService $service): int
    {
        $seuilMois = (int) $this->option('seuil-mois');
        $limite = now()->subMonths($seuilMois);

        $candidatures = Courrier::query()
            ->withoutGlobalScopes()
            ->where('type', \Modules\Courrier\Enums\CourrierType::DEMANDE_STAGE)
            ->where('avis_dg', \Modules\Courrier\Enums\AvisDg::DEFAVORABLE)
            ->whereNull('anonymise_at')
            ->where('avis_dg_rendu_at', '<=', $limite)
            ->get();

        foreach ($candidatures as $courrier) {
            $cheminsAFichiers = $service->cheminsFichiersAEffacer($courrier);

            if ($service->anonymiser($courrier, $seuilMois)) {
                $service->supprimerFichiersPhysiques($cheminsAFichiers);
            }
        }

        $this->info("{$candidatures->count()} candidature(s) non retenue(s) anonymisée(s) (délai : {$seuilMois} mois).");

        return self::SUCCESS;
    }
}
