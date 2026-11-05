<?php

namespace Modules\Stagiaires\Console;

use Illuminate\Console\Command;
use Modules\Courrier\Services\AnonymiserCandidatureService;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Services\AnonymiserStagiaireNonRetenuService;

/**
 * Pendant de courrier:anonymiser-candidatures-non-retenues pour les refus du tableau de
 * répartition (NON_RETENU), qui n'ont pas d'avis DG défavorable et n'étaient donc pas couverts.
 */
class AnonymiserStagiairesNonRetenusCommand extends Command
{
    protected $signature = 'stagiaires:anonymiser-non-retenus {--seuil-mois=12}';

    protected $description = 'Anonymise les candidatures écartées au tableau de répartition, passé le délai de conservation';

    public function handle(AnonymiserStagiaireNonRetenuService $service, AnonymiserCandidatureService $candidatures): int
    {
        $seuilMois = (int) $this->option('seuil-mois');
        $total = 0;

        Stagiaire::query()
            ->withoutGlobalScopes()
            ->where('statut', StagiaireStatut::NON_RETENU)
            ->where('non_retenu_at', '<=', now()->subMonths($seuilMois))
            ->where('nom', '!=', AnonymiserStagiaireNonRetenuService::NOM_ANONYME)
            ->each(function (Stagiaire $stagiaire) use ($service, $candidatures, $seuilMois, &$total) {
                $chemins = $service->anonymiser($stagiaire, $seuilMois);
                if ($chemins !== null) {
                    $candidatures->supprimerFichiersPhysiques($chemins);
                    $total++;
                }
            });

        $this->info("{$total} candidature(s) écartée(s) au tableau anonymisée(s) (délai : {$seuilMois} mois).");

        return self::SUCCESS;
    }
}
