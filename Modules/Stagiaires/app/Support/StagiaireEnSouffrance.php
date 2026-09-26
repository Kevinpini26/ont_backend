<?php

namespace Modules\Stagiaires\Support;

use Illuminate\Support\Collection;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;

/**
 * Liste des dossiers stagiaire "en souffrance" — même principe que
 * Modules\Courrier\Support\CourrierEnSouffrance, adapté à l'absence
 * d'historique de transitions ici (voir Stagiaire::statut_change_at,
 * seul repère disponible pour dater l'entrée dans l'étape courante).
 * Purement indicatif : ne bloque jamais rien, juste un signalement pour
 * l'utilisateur concerné par l'étape en cours.
 */
class StagiaireEnSouffrance
{
    private const STATUTS_TERMINAUX = [StagiaireStatut::CLOTURE, StagiaireStatut::NON_RETENU];

    /**
     * @return Collection<int, array{stagiaire: Stagiaire, anciennete_heures: int, seuil_heures: int, niveau: 1|2|3}>
     */
    public function pourUtilisateur(User $utilisateur): Collection
    {
        $seuilDefaut = (int) config('stagiaires.delai_indicatif_heures_par_defaut', 72);
        $delaisParStatut = config('stagiaires.delais_indicatifs_heures', []);

        return Stagiaire::query()
            ->whereNotIn('statut', array_map(fn ($s) => $s->value, self::STATUTS_TERMINAUX))
            ->get()
            ->filter(fn (Stagiaire $stagiaire) => $this->actionnableParUtilisateur($stagiaire, $utilisateur))
            ->map(function (Stagiaire $stagiaire) use ($delaisParStatut, $seuilDefaut) {
                $depuis = $stagiaire->statut_change_at ?? $stagiaire->created_at;
                $heures = (int) $depuis->diffInHours(now());
                $seuil = (int) ($delaisParStatut[$stagiaire->statut->value] ?? $seuilDefaut);
                $niveau = (int) match (true) {
                    $heures >= $seuil * 3 => 3,
                    $heures >= $seuil * 2 => 2,
                    $heures >= $seuil => 1,
                    default => 0,
                };

                return [
                    'stagiaire' => $stagiaire,
                    'anciennete_heures' => $heures,
                    'seuil_heures' => $seuil,
                    'niveau' => $niveau,
                ];
            })
            ->filter(fn (array $ligne) => $ligne['niveau'] > 0)
            ->sortByDesc('anciennete_heures')
            ->values();
    }

    private function actionnableParUtilisateur(Stagiaire $stagiaire, User $utilisateur): bool
    {
        return match ($stagiaire->statut) {
            StagiaireStatut::DOSSIER_RECU,
            StagiaireStatut::EN_ATTENTE_AFFECTATION,
            StagiaireStatut::EN_INSTRUCTION,
            StagiaireStatut::AFFECTE,
            StagiaireStatut::STAGE_EN_COURS => $utilisateur->role === UserRole::AGENT_DFP,

            // En évaluation, aussi bien la DFP (sa propre grille) que la
            // direction d'accueil (la sienne) sont responsables d'agir.
            StagiaireStatut::EVALUATION_EN_COURS => $utilisateur->role === UserRole::AGENT_DFP
                || (($utilisateur->role->estDirecteurDirection() || $utilisateur->role === UserRole::SECRETARIAT_DIRECTION)
                    && $utilisateur->direction_id === $stagiaire->direction_id),

            default => false,
        };
    }
}
