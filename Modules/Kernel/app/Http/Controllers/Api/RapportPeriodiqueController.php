<?php

namespace Modules\Kernel\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Contracts\PdfGenerationService;
use Modules\Stagiaires\Models\Stagiaire;

/**
 * Rapport de synthèse destiné à la tutelle : seul endpoit du système à
 * composer directement des données de deux modules métier (Courrier,
 * Stagiaires) dans un même document. Exception délibérée et limitée à ce
 * seul contrôleur de reporting en lecture seule — les autres modules
 * restent découplés entre eux (ils ne communiquent que par événements).
 * Pas de point d'extension (interface swappable) : il n'existe pas de
 * second format de rapport à substituer, l'ajouter serait de la
 * sur-ingénierie.
 */
class RapportPeriodiqueController extends Controller
{
    public function __construct(private readonly PdfGenerationService $pdf) {}

    public function genererPdf(Request $request)
    {
        Gate::authorize('genererRapportTutelle');

        $validated = $request->validate([
            'type' => ['required', Rule::in(['mois', 'annee'])],
            'annee' => ['required', 'integer', 'min:2020', 'max:2100'],
            'mois' => ['required_if:type,mois', 'nullable', 'integer', 'min:1', 'max:12'],
        ]);

        [$debut, $fin, $libellePeriode] = $this->resoudrePeriode($validated);

        $courriersParDirectionEtStatut = DB::table('courriers')
            ->join('directions', 'directions.id', '=', DB::raw('coalesce(courriers.direction_destination_id, courriers.direction_origine_id)'))
            ->whereBetween('courriers.created_at', [$debut, $fin])
            ->select('directions.code as direction_code', 'directions.nom as direction_nom', 'courriers.statut', DB::raw('count(*) as total'))
            ->groupBy('directions.code', 'directions.nom', 'courriers.statut')
            ->orderBy('directions.code')
            ->get()
            ->groupBy('direction_code');

        $totalCourriers = Courrier::query()
            ->withoutGlobalScopes()
            ->whereBetween('created_at', [$debut, $fin])
            ->count();

        $stagiairesParDirection = DB::table('stagiaires')
            ->join('directions', 'directions.id', '=', 'stagiaires.direction_id')
            ->whereBetween('stagiaires.affecte_at', [$debut, $fin])
            ->select('directions.code as direction_code', 'directions.nom as direction_nom', DB::raw('count(*) as total'))
            ->groupBy('directions.code', 'directions.nom')
            ->orderBy('directions.code')
            ->get();

        $totalStagiaires = Stagiaire::query()
            ->withoutGlobalScopes()
            ->whereBetween('affecte_at', [$debut, $fin])
            ->count();

        $contenu = $this->pdf->genererDepuisVue('kernel::rapport-periodique', [
            'libellePeriode' => $libellePeriode,
            'debut' => $debut,
            'fin' => $fin,
            'courriersParDirectionEtStatut' => $courriersParDirectionEtStatut,
            'totalCourriers' => $totalCourriers,
            'stagiairesParDirection' => $stagiairesParDirection,
            'totalStagiaires' => $totalStagiaires,
            'statuts' => CourrierStatut::cases(),
        ]);

        $mois = $validated['mois'] ?? null;
        $nomFichier = "rapport-ont-{$validated['annee']}".($mois ? '-'.str_pad((string) $mois, 2, '0', STR_PAD_LEFT) : '').'.pdf';

        return response($contenu, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$nomFichier}\"",
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function resoudrePeriode(array $validated): array
    {
        if ($validated['type'] === 'mois') {
            $debut = now()->setDate($validated['annee'], $validated['mois'], 1)->startOfDay();
            $fin = $debut->copy()->endOfMonth()->endOfDay();
            $libelle = $debut->translatedFormat('F Y');
        } else {
            $debut = now()->setDate($validated['annee'], 1, 1)->startOfDay();
            $fin = $debut->copy()->endOfYear()->endOfDay();
            $libelle = "Année {$validated['annee']}";
        }

        return [$debut, $fin, $libelle];
    }
}
