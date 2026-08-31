<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Courrier\Enums\NumerisationStatut;
use Modules\Courrier\Http\Resources\CourrierResource;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;

/**
 * Liste de rattrapage des courriers enregistrés sans que la numérisation
 * ait pu être faite le jour même (coupure de courant, copieur en panne,
 * téléphone déchargé — voir docs/numerisation-courrier.md) : le dépôt
 * n'est jamais bloqué pour autant, ce tableau permet juste de ne pas les
 * perdre de vue. Réservé au poste qui numérise (la Réception) et à
 * l'administrateur — même principe que les autres tableaux de bord
 * transverses restreints par poste (voir CourrierPolicy::voirRegistre()).
 */
class CourrierRattrapageNumerisationController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(
            $request->user()->role === UserRole::ADMINISTRATEUR
                || $request->user()->poste === Poste::RECEPTION,
            403,
        );

        $courriers = Courrier::query()
            ->where('numerisation_statut', NumerisationStatut::A_NUMERISER)
            ->with(['directionOrigine', 'directionDestination'])
            ->oldest('created_at')
            ->get();

        return CourrierResource::collection($courriers);
    }
}
