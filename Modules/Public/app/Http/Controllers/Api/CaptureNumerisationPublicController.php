<?php

namespace Modules\Public\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Modules\Courrier\Enums\NumerisationStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\SourceDocumentNumerise;
use Modules\Kernel\Models\JetonCaptureNumerisation;
use Modules\Kernel\Support\GestionnaireDocumentNumerise;
use Modules\Public\Http\Requests\SoumettreCaptureNumerisationRequest;
use Modules\Stagiaires\Models\Stagiaire;

/**
 * Point d'accès public (sans authentification) de la page de capture
 * mobile — voir docs/numerisation-courrier.md. Le jeton, généré par un
 * agent authentifié, tient lieu d'autorisation.
 */
class CaptureNumerisationPublicController extends Controller
{
    public function __construct(private readonly GestionnaireDocumentNumerise $gestionnaire) {}

    private function trouverJetonValide(string $token): JetonCaptureNumerisation
    {
        $jeton = JetonCaptureNumerisation::query()->where('token', $token)->with('capturable')->firstOrFail();

        // 404 générique (jeton inconnu, déjà consommé ou expiré) : le jeton
        // (48 caractères aléatoires) n'est de toute façon pas énumérable,
        // mais autant ne pas confirmer par le code HTTP qu'un lien donné a
        // existé — même principe que LienPublicController::trouverLienValide().
        abort_unless($jeton->estValide(), 404);

        return $jeton;
    }

    public function show(string $token)
    {
        $jeton = $this->trouverJetonValide($token);
        $cible = $jeton->capturable;

        return response()->json([
            'cible_type' => $cible instanceof Courrier ? 'courrier' : 'stagiaire',
            'cible_libelle' => $cible instanceof Courrier ? $cible->objet : $cible->nom,
            'expire_at' => $jeton->expire_at,
            'plafond_ko' => $cible instanceof Stagiaire
                ? config('kernel.numerisation.plafond_ko_stagiaire')
                : config('kernel.numerisation.plafond_ko_courrier'),
        ]);
    }

    public function soumettre(SoumettreCaptureNumerisationRequest $request, string $token)
    {
        $jeton = $this->trouverJetonValide($token);
        $cible = $jeton->capturable;

        $chemin = $request->file('fichier')->store('numerisations', 'local');

        $document = $this->gestionnaire->enregistrerVersion(
            $cible,
            $chemin,
            SourceDocumentNumerise::TELEPHONE,
            $jeton->creePar,
            $request->integer('nombre_pages_annonce') ?: null,
        );

        if ($cible instanceof Courrier) {
            $cible->update(['numerisation_statut' => NumerisationStatut::NUMERISE]);
        }

        $jeton->consommer();

        return response()->json([
            'message' => 'Document reçu avec succès.',
            'document' => [
                'version' => $document->version,
                'nombre_pages' => $document->nombre_pages,
                'qualite' => $document->qualite?->value,
            ],
        ], 201);
    }
}
