<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\EmpruntOriginal;

/**
 * Liste des originaux actuellement sortis, avec alerte au-delà de 7 jours
 * — voir docs/numerisation-courrier.md (Lot 5).
 */
class EmpruntOriginalController extends Controller
{
    public function index(Request $request)
    {
        // Vue transverse à toutes les directions : même périmètre que les
        // autres tableaux de bord du circuit central (voir
        // CourrierPolicy::voirRegistre()/voirStatistiques()).
        $this->authorize('voirRegistre', Courrier::class);

        $emprunts = EmpruntOriginal::query()
            ->whereNull('restitue_le')
            // La relation Courrier porte le scope de visibilité : ne pas
            // charger puis déréférencer un courrier masqué (null), mais
            // exclure l'emprunt de la collection dès la requête.
            ->whereHas('courrier')
            ->with(['courrier', 'empruntePar'])
            ->oldest('emprunte_le')
            ->get();

        return response()->json([
            'data' => $emprunts->map(fn (EmpruntOriginal $emprunt) => [
                'id' => $emprunt->id,
                'courrier_id' => $emprunt->courrier_id,
                'numero_accuse_reception' => $emprunt->courrier->numero_accuse_reception,
                'objet' => $emprunt->courrier->objet,
                'emprunte_par' => $emprunt->empruntePar->name,
                'emprunte_le' => $emprunt->emprunte_le,
                'motif' => $emprunt->motif,
                'jours_emprunte' => (int) $emprunt->emprunte_le->diffInDays(now()),
                'alerte' => $emprunt->emprunte_le->diffInDays(now()) > 7,
            ])->values(),
        ]);
    }
}
