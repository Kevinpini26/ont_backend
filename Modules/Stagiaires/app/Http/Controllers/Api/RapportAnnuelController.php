<?php

namespace Modules\Stagiaires\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Stagiaires\Contracts\RapportAnnuelGenerator;
use Modules\Stagiaires\Models\Stagiaire;

class RapportAnnuelController extends Controller
{
    public function __construct(private readonly RapportAnnuelGenerator $generateur) {}

    public function telecharger(Request $request)
    {
        $this->authorize('voirRapportAnnuel', Stagiaire::class);

        $request->validate(['annee' => ['required', 'integer', 'min:2000', 'max:2100']]);
        $annee = $request->integer('annee');

        $pdf = $this->generateur->generer($annee);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"rapport-annuel-stagiaires-{$annee}.pdf\"",
        ]);
    }
}
