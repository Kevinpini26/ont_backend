<?php

namespace Modules\Courrier\Support;

use Illuminate\Support\Collection;
use Modules\Courrier\Contracts\CircuitTransitionRules;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\SensCourrier;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Models\User;

/**
 * Liste des courriers "en souffrance" — non traités depuis plus longtemps
 * que le délai indicatif de leur étape courante — pour le périmètre d'un
 * utilisateur précis (son poste, ou le relecteur désigné). Distinct de
 * CourriersNonTraites (un simple compteur global) : ici, chaque ligne
 * porte son ancienneté et son niveau de gravité, triée pour affichage.
 */
class CourrierEnSouffrance
{
    public function __construct(private readonly CircuitTransitionRules $regles) {}

    /**
     * @return Collection<int, array{courrier: Courrier, anciennete_heures: int, seuil_heures: int, niveau: int}>
     */
    public function pourUtilisateur(User $utilisateur): Collection
    {
        $seuilDefaut = (int) config('courrier.delai_indicatif_heures_par_defaut', 48);
        $delaisParStatut = config('courrier.delais_indicatifs_heures', []);

        return Courrier::query()
            ->whereNotIn('statut', [CourrierStatut::ENREGISTRE->value, CourrierStatut::ENVOYE->value])
            ->with('transitions')
            ->get()
            ->filter(fn (Courrier $courrier) => $this->actionnableParUtilisateur($courrier, $utilisateur))
            ->map(function (Courrier $courrier) use ($delaisParStatut, $seuilDefaut) {
                $bordereau = $courrier->bordereauCourant();
                $depuis = $bordereau?->created_at ?? $courrier->created_at;
                $heures = $depuis->diffInHours(now());
                $seuil = (int) ($delaisParStatut[$courrier->statut->value] ?? $seuilDefaut);

                return [
                    'courrier' => $courrier,
                    'anciennete_heures' => $heures,
                    'seuil_heures' => $seuil,
                    'niveau' => match (true) {
                        $heures >= $seuil * 3 => 3,
                        $heures >= $seuil * 2 => 2,
                        $heures >= $seuil => 1,
                        default => 0,
                    },
                ];
            })
            ->filter(fn (array $ligne) => $ligne['niveau'] > 0)
            ->sortByDesc('anciennete_heures')
            ->values();
    }

    private function actionnableParUtilisateur(Courrier $courrier, User $utilisateur): bool
    {
        if ($courrier->enAttenteValidationRelecteur()) {
            return $courrier->relecteur_id === $utilisateur->id;
        }

        if ($utilisateur->poste === null) {
            return false;
        }

        $estSortant = $courrier->sens === SensCourrier::SORTANT;
        $postes = $this->regles->postesAutorises($courrier->statut, $courrier->necessite_avis_dg, $courrier->initie_par_dg, $estSortant);

        return in_array($utilisateur->poste, $postes, true);
    }
}
