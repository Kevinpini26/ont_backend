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

            if ($courrierVerrouille->avis_dg !== AvisDg::DEFAVORABLE) {
                return false;
            }

            $limite = now()->subMonths($seuilMois);
            if ($courrierVerrouille->avis_dg_rendu_at === null || $courrierVerrouille->avis_dg_rendu_at->gt($limite)) {
                return false;
            }

            return $this->effacer($courrierVerrouille, ['seuil_mois' => $seuilMois, 'origine' => 'avis_dg_defavorable']);
        });
    }

    /**
     * Candidature écartée au tableau de répartition (dossier NON_RETENU) : l'éligibilité (statut,
     * délai) est vérifiée par le module Stagiaires, qui appelle cette méthode dans sa transaction.
     */
    public function anonymiserNonRetenu(Courrier $courrier, int $seuilMois): bool
    {
        $courrierVerrouille = Courrier::withoutGlobalScopes()->lockForUpdate()->findOrFail($courrier->id);

        return $this->effacer($courrierVerrouille, ['seuil_mois' => $seuilMois, 'origine' => 'non_retenu_tableau']);
    }

    /** @param  array<string, mixed>  $contexte */
    private function effacer(Courrier $courrierVerrouille, array $contexte): bool
    {
        if ($courrierVerrouille->type !== CourrierType::DEMANDE_STAGE || $courrierVerrouille->anonymise_at !== null) {
            return false;
        }

        $cheminsAConserver = $this->cheminsFichiersAEffacer($courrierVerrouille);

        $payload = [
            'candidat_nom' => null,
            'candidat_contact' => null,
            'candidat_email' => null,
            'candidat_etablissement' => null,
            'expediteur_externe_nom' => null,
            'expediteur_externe_email' => null,
            'expediteur_externe_telephone' => null,
            'objet' => 'Candidature anonymisée',
            'contenu' => ['type' => 'doc', 'content' => []],
            'avis_dg_commentaire' => null,
            'note_technique' => null,
            'projet_reponse_contenu' => null,
            'relecture_commentaire' => null,
            'piece_jointe_chemin' => null,
            'lettre_stage_chemin' => null,
            'cv_chemin' => null,
            'diplome_etat_chemin' => null,
            'dernier_diplome_chemin' => null,
            'lettre_demande_chemin' => null,
            'anonymise_at' => now(),
        ];

        $courrierVerrouille->forceFill($payload)->saveQuietly();
        $courrierVerrouille->annotations()->delete();

        // Le journal d'audit n'est jamais supprimé ni réécrit : l'anonymisation y ajoute une entrée.
        $this->audit->enregistrer('courrier.candidature_anonymisee', $courrierVerrouille, null, $contexte + [
            'description' => 'Anonymisation d’une candidature non retenue',
            'archive' => $courrierVerrouille->estArchive(),
            'fichiers_supprimes' => $cheminsAConserver,
        ]);

        return true;
    }

    /**
     * @return array<int, string>
     */
    public function cheminsFichiersAEffacer(Courrier $courrier): array
    {
        return array_values(array_filter([
            $courrier->piece_jointe_chemin,
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
