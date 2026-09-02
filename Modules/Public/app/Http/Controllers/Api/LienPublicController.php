<?php

namespace Modules\Public\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\Public\Http\Requests\SoumettreRapportStageRequest;
use Modules\Public\Http\Requests\SoumettreRetourRequest;
use Modules\Public\Http\Resources\LienPublicResource;
use Modules\Stagiaires\Enums\DocumentType;
use Modules\Stagiaires\Enums\TypeLienPublic;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Models\StagiaireLienPublic;
use Modules\Stagiaires\Models\StagiaireRetour;
use Modules\Stagiaires\Services\StagiaireCircuitService;

/**
 * Points d'accès publics (sans authentification) accessibles via un lien à
 * usage unique envoyé à un stagiaire : signature de convention et retour
 * d'expérience. Un stagiaire n'a pas de compte utilisateur Sanctum, ces
 * actions ne peuvent donc pas passer par le circuit authentifié habituel.
 */
class LienPublicController extends Controller
{
    public function __construct(private readonly StagiaireCircuitService $circuit) {}

    private function trouverLienValide(string $token, TypeLienPublic $type): StagiaireLienPublic
    {
        // ->first() + abort_unless (pas firstOrFail) : voir le commentaire de
        // show() — un jeton inconnu ne doit jamais faire remonter le message
        // brut d'une ModelNotFoundException à l'appelant.
        $lien = StagiaireLienPublic::query()
            ->where('token', $token)
            ->where('type', $type)
            ->with(['stagiaire.direction'])
            ->first();

        // 404 générique, sans message distinguant "lien déjà utilisé" d'un
        // jeton simplement inconnu : le jeton (48 caractères aléatoires)
        // n'est de toute façon pas énumérable, mais autant ne pas confirmer
        // par le code HTTP qu'un lien donné a bien existé. Message explicite
        // (pas de valeur par défaut) : un message vide reste une chaîne
        // "truthy-absente" côté JS (`?? secours` ne s'applique qu'à null/
        // undefined), PublicLienPage.jsx afficherait une alerte vide sinon.
        abort_unless($lien && $lien->estValide(), 404, 'Ce lien est introuvable ou invalide.');

        return $lien;
    }

    public function show(string $token)
    {
        // ->first() + abort_unless (pas firstOrFail) : un jeton inconnu est un
        // cas attendu, atteint par n'importe qui tapant une URL au hasard, pas
        // une erreur de programmation — firstOrFail() lève une
        // ModelNotFoundException dont Laravel sérialise le message brut
        // ("No query results for model [...]") dans la réponse JSON, exposé
        // tel quel par PublicLienPage.jsx à l'utilisateur final. abort(404)
        // produit une réponse générique, sans détail d'implémentation.
        $lien = StagiaireLienPublic::query()->where('token', $token)->with(['stagiaire.direction'])->first();

        abort_unless($lien !== null, 404, 'Ce lien est introuvable ou invalide.');

        return new LienPublicResource($lien);
    }

    public function telechargerConvention(string $token)
    {
        $lien = StagiaireLienPublic::query()
            ->where('token', $token)
            ->where('type', TypeLienPublic::CONVENTION)
            ->with('stagiaire')
            ->first();

        abort_unless($lien && $lien->stagiaire->convention_chemin, 404, 'Aucune convention disponible pour ce lien.');

        return Storage::disk('local')->download(
            $lien->stagiaire->convention_chemin,
            "convention-stage-{$lien->stagiaire->nom}.pdf",
        );
    }

    public function signerConvention(string $token)
    {
        $lien = $this->trouverLienValide($token, TypeLienPublic::CONVENTION);

        // stagiaire_id est une colonne obligatoire de stagiaire_liens_publics
        // et la relation est chargée par trouverLienValide() — jamais nulle
        // en pratique, contrairement à ce que le type générique de la
        // relation BelongsTo laisse penser.
        /** @var Stagiaire $stagiaire */
        $stagiaire = $lien->stagiaire;
        $this->circuit->signerConventionStagiaire($stagiaire);
        $lien->consommer();

        return response()->json(['message' => 'Convention signée avec succès.']);
    }

    public function soumettreRetour(SoumettreRetourRequest $request, string $token)
    {
        $lien = $this->trouverLienValide($token, TypeLienPublic::RETOUR_EXPERIENCE);

        StagiaireRetour::query()->create([
            ...$request->validated(),
            'stagiaire_id' => $lien->stagiaire_id,
            'created_at' => now(),
        ]);

        $lien->consommer();

        return response()->json(['message' => 'Merci pour votre retour.']);
    }

    public function signerEngagementConfidentialite(string $token)
    {
        $lien = $this->trouverLienValide($token, TypeLienPublic::ENGAGEMENT_CONFIDENTIALITE);

        /** @var Stagiaire $stagiaire */
        $stagiaire = $lien->stagiaire;
        $this->circuit->signerEngagementConfidentialite($stagiaire);
        $lien->consommer();

        return response()->json(['message' => 'Engagement de confidentialité signé avec succès.']);
    }

    public function soumettreRapportStage(SoumettreRapportStageRequest $request, string $token)
    {
        $lien = $this->trouverLienValide($token, TypeLienPublic::RAPPORT_STAGE);

        /** @var Stagiaire $stagiaire */
        $stagiaire = $lien->stagiaire;
        $fichier = $request->file('fichier');
        $chemin = $fichier->store("stagiaires/{$stagiaire->id}", 'local');

        // Aucun utilisateur ONT authentifié n'est à l'origine d'un dépôt
        // public : uploaded_by_id reste nul, comme creerDepuisPublic() côté
        // courrier (created_by null).
        $this->circuit->ajouterDocument(
            $stagiaire,
            null,
            DocumentType::RAPPORT_FIN_STAGE,
            $fichier->getClientOriginalName(),
            $chemin,
        );

        $lien->consommer();

        return response()->json(['message' => 'Rapport de fin de stage déposé avec succès.']);
    }
}
