<?php

namespace Modules\Public\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Courrier\Services\CourrierCircuitService;
use Modules\Public\Http\Requests\DeposerDemandeStageRequest;

class DemandeStagePublicController extends Controller
{
    public function __construct(private readonly CourrierCircuitService $circuit) {}

    /**
     * Dépôt en ligne d'une demande de stage par un candidat externe.
     * Endpoint public : aucune authentification requise. Génère le numéro
     * d'accusé de réception et déclenche l'e-mail de confirmation au
     * candidat (voir CourrierCircuitService::creerDepuisPublic).
     */
    /**
     * @var array<string, array{colonne: string, dossier: string}> Champ fichier du formulaire →
     *                                                             colonne de stockage sur Courrier, et dossier de destination sur le disque local.
     */
    private const PIECES = [
        'lettre_stage' => ['colonne' => 'lettre_stage_chemin', 'dossier' => 'lettres-stage'],
        'lettre_demande' => ['colonne' => 'lettre_demande_chemin', 'dossier' => 'lettres-demande-stage'],
        'cv' => ['colonne' => 'cv_chemin', 'dossier' => 'cv-candidats'],
        'diplome_etat' => ['colonne' => 'diplome_etat_chemin', 'dossier' => 'diplomes-etat'],
        'dernier_diplome' => ['colonne' => 'dernier_diplome_chemin', 'dossier' => 'derniers-diplomes'],
    ];

    public function store(DeposerDemandeStageRequest $request): JsonResponse
    {
        $donnees = $request->validated();
        $chemins = [];

        foreach (self::PIECES as $champ => $config) {
            if (! isset($donnees[$champ])) {
                continue;
            }

            $chemins[$config['colonne']] = $donnees[$champ]->store($config['dossier'], 'local');
            unset($donnees[$champ]);
        }

        $courrier = $this->circuit->creerDepuisPublic([
            ...$donnees,
            'objet' => "Demande de stage — {$donnees['candidat_nom']}",
            ...$chemins,
        ]);

        return response()->json([
            'numero_accuse_reception' => $courrier->numero_accuse_reception,
        ], 201);
    }
}
