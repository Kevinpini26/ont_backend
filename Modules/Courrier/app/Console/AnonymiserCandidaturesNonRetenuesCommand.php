<?php

namespace Modules\Courrier\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\AvisDg;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Models\Courrier;

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

    public function handle(): int
    {
        $seuilMois = (int) $this->option('seuil-mois');
        $limite = now()->subMonths($seuilMois);

        // withoutGlobalScopes : tâche système sans utilisateur authentifié,
        // doit voir toutes les directions — cohérent avec
        // RapportPeriodiqueController pour la même raison.
        $candidatures = Courrier::query()
            ->withoutGlobalScopes()
            ->where('type', CourrierType::DEMANDE_STAGE)
            ->where('avis_dg', AvisDg::DEFAVORABLE)
            ->whereNull('anonymise_at')
            ->where('avis_dg_rendu_at', '<=', $limite)
            ->get();

        foreach ($candidatures as $courrier) {
            $courrier->annotations()->delete();

            foreach (['lettre_stage_chemin', 'cv_chemin', 'diplome_etat_chemin', 'dernier_diplome_chemin', 'lettre_demande_chemin'] as $colonne) {
                if ($courrier->{$colonne}) {
                    Storage::disk('local')->delete($courrier->{$colonne});
                }
            }

            $courrier->update([
                'candidat_nom' => null,
                'candidat_contact' => null,
                'candidat_etablissement' => null,
                'expediteur_externe_nom' => null,
                'objet' => 'Candidature anonymisée',
                'contenu' => ['type' => 'doc', 'content' => []],
                'avis_dg_commentaire' => null,
                'note_technique' => null,
                'projet_reponse_contenu' => null,
                'relecture_commentaire' => null,
                'lettre_stage_chemin' => null,
                'cv_chemin' => null,
                'diplome_etat_chemin' => null,
                'dernier_diplome_chemin' => null,
                'lettre_demande_chemin' => null,
                'anonymise_at' => now(),
            ]);
        }

        $this->info("{$candidatures->count()} candidature(s) non retenue(s) anonymisée(s) (délai : {$seuilMois} mois).");

        return self::SUCCESS;
    }
}
