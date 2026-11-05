<?php

namespace Modules\Stagiaires\Services;

use Illuminate\Support\Facades\DB;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Services\AnonymiserCandidatureService;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;

/**
 * Candidature écartée au tableau de répartition (statut NON_RETENU) : passé le délai de conservation,
 * l'identité du candidat est effacée de la fiche stagiaire et de sa demande (courrier). Le statut,
 * le motif et les dates restent pour les statistiques ; le journal d'audit n'est jamais supprimé.
 * Les pièces du dossier (documents, numérisations) ne sont pas touchées : voir docs/questions-ont.md.
 */
class AnonymiserStagiaireNonRetenuService
{
    public const NOM_ANONYME = 'Candidat anonymisé';

    public function __construct(
        private readonly AnonymiserCandidatureService $candidatures,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<int, string> chemins de fichiers du courrier à supprimer après validation */
    public function anonymiser(Stagiaire $stagiaire, int $seuilMois): ?array
    {
        return DB::transaction(function () use ($stagiaire, $seuilMois) {
            $verrouille = Stagiaire::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($stagiaire->id);

            if ($verrouille->statut !== StagiaireStatut::NON_RETENU
                || $verrouille->non_retenu_at === null
                || $verrouille->non_retenu_at->gt(now()->subMonths($seuilMois))
                || $verrouille->nom === self::NOM_ANONYME) {
                return null;
            }

            $verrouille->forceFill([
                'nom' => self::NOM_ANONYME,
                'contact' => '—',
                'lieu_naissance' => null,
            ])->saveQuietly();

            $chemins = [];
            $courrier = $verrouille->courrier_id === null ? null : Courrier::withoutGlobalScopes()->find($verrouille->courrier_id);
            if ($courrier !== null) {
                $chemins = $this->candidatures->cheminsFichiersAEffacer($courrier);
                if (! $this->candidatures->anonymiserNonRetenu($courrier, $seuilMois)) {
                    $chemins = [];
                }
            }

            $this->audit->enregistrer('stagiaire.non_retenu_anonymise', $verrouille, null, [
                'description' => 'Anonymisation d’une candidature non retenue au tableau de répartition',
                'seuil_mois' => $seuilMois,
            ]);

            return $chemins;
        });
    }
}
