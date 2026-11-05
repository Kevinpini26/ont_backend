<?php

namespace Modules\Stagiaires\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Modules\Courrier\Contracts\RegistreCourrierPdfGenerator;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Support\ZipArchiveBuilder;
use Modules\Stagiaires\Contracts\RapportAnnuelGenerator;

/**
 * Export d'archive annuelle consolidée (registres courrier arrivée/départ,
 * rapport annuel des stages) — un seul fichier téléchargeable, à
 * destination de la tutelle ou de l'Institut National des Archives du
 * Congo (INACO), voir docs/conformite-donnees.md (articles 42 à 46 de
 * l'ordonnance-loi n°23/010). Placé dans Stagiaires, qui dépend déjà de
 * Courrier (voir Stagiaire::courrier()) — jamais l'inverse.
 */
class ArchiveAnnuelleController extends Controller
{
    public function __construct(
        private readonly RegistreCourrierPdfGenerator $registres,
        private readonly RapportAnnuelGenerator $rapportAnnuel,
    ) {}

    public function telecharger(Request $request)
    {
        abort_unless(
            in_array($request->user()->role, [UserRole::AGENT_DFP, UserRole::ADMINISTRATEUR], true),
            403,
        );

        $request->validate(['annee' => ['required', 'integer', 'min:2000', 'max:2100']]);
        $annee = $request->integer('annee');
        $debut = Carbon::create($annee, 1, 1)->startOfDay();
        $fin = Carbon::create($annee, 12, 31)->endOfDay();

        // Matrice d'accès (proposition F) : les registres du courrier n'entrent dans l'archive que pour
        // un poste qui a droit au registre ; la DFP et l'administrateur reçoivent le rapport des stages.
        $fichiers = [];
        if (Gate::allows('voirRegistre', Courrier::class)) {
            $fichiers["registre-courrier-arrivee-{$annee}.pdf"] = $this->registres->generer('arrivee', $debut, $fin);
            $fichiers["registre-courrier-depart-{$annee}.pdf"] = $this->registres->generer('depart', $debut, $fin);
        }
        $fichiers["rapport-annuel-stagiaires-{$annee}.pdf"] = $this->rapportAnnuel->generer($annee);
        $zip = ZipArchiveBuilder::construire($fichiers);

        return response($zip, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => "attachment; filename=\"archive-annuelle-ont-{$annee}.zip\"",
        ]);
    }
}
