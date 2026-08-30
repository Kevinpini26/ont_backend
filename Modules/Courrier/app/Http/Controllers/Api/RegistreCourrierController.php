<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Courrier\Contracts\RegistreCourrierPdfGenerator;
use Modules\Courrier\Models\Courrier;

class RegistreCourrierController extends Controller
{
    public function __construct(private readonly RegistreCourrierPdfGenerator $generateur) {}

    public function telecharger(Request $request)
    {
        $this->authorize('voirRegistre', Courrier::class);

        $request->validate([
            'debut' => ['required', 'date'],
            'fin' => ['required', 'date', 'after_or_equal:debut'],
            'type' => ['nullable', 'in:arrivee,depart'],
        ]);

        $type = $request->string('type', 'arrivee')->toString();
        $debut = Carbon::parse($request->string('debut'))->startOfDay();
        $fin = Carbon::parse($request->string('fin'))->endOfDay();

        $pdf = $this->generateur->generer($type, $debut, $fin);

        $nomFichier = sprintf('registre-%s-%s-au-%s.pdf', $type, $debut->toDateString(), $fin->toDateString());

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$nomFichier}\"",
        ]);
    }
}
