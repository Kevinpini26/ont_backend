<?php

namespace Modules\Courrier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\AvisDg;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Contracts\AuditLogger;

class AnonymiserCandidatureService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function anonymiser(Courrier $courrier, int $seuilMois): bool
    {
        return DB::transaction(function () use ($courrier, $seuilMois) {
            $courrierVerrouille = Courrier::withoutGlobalScopes()->lockForUpdate()->findOrFail($courrier->id);

            if ($courrierVerrouille->type !== CourrierType::DEMANDE_STAGE) {
                return false;
            }

            if ($courrierVerrouille->avis_dg !== AvisDg::DEFAVORABLE) {
                return false;
            }

            if ($courrierVerrouille->anonymise_at !== null) {
                return false;
            }

            $limite = now()->subMonths($seuilMois);
            if ($courrierVerrouille->avis_dg_rendu_at === null || $courrierVerrouille->avis_dg_rendu_at->gt($limite)) {
                return false;
            }

            $cheminsAConserver = $this->cheminsFichiersAEffacer($courrierVerrouille);

            $payload = [
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
            ];

            $courrierVerrouille->forceFill($payload)->saveQuietly();
            $courrierVerrouille->annotations()->delete();

            $this->audit->enregistrer('courrier.candidature_anonymisee', $courrierVerrouille, null, [
                'description' => 'Anonymisation d’une candidature non retenue',
                'seuil_mois' => $seuilMois,
                'archive' => $courrierVerrouille->estArchive(),
                'fichiers_supprimes' => $cheminsAConserver,
            ]);

            return true;
        });
    }

    /**
     * @return array<int, string>
     */
    public function cheminsFichiersAEffacer(Courrier $courrier): array
    {
        return array_values(array_filter([
            $courrier->lettre_stage_chemin,
            $courrier->cv_chemin,
            $courrier->diplome_etat_chemin,
            $courrier->dernier_diplome_chemin,
            $courrier->lettre_demande_chemin,
        ]));
    }

    /**
     * Suppression physique des anciens fichiers après la transaction DB.
     * Une erreur Storage ne rétablit pas les champs effacés DB, mais elle est
     * signalée et ne doit pas faire tomber l’opération métier.
     *
     * @param  array<int, string>  $chemins
     */
    public function supprimerFichiersPhysiques(array $chemins): void
    {
        foreach ($chemins as $chemin) {
            try {
                Storage::disk('local')->delete($chemin);
            } catch (\Throwable $e) {
                report($e);
                $this->audit->enregistrer('courrier.candidature_anonymisee_fichiers_echec', null, null, [
                    'description' => 'Échec de suppression des fichiers lors de l’anonymisation',
                    'chemin' => $chemin,
                ]);
            }
        }
    }
}
