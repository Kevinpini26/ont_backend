<?php

namespace Modules\Public\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Models\Courrier;
use Modules\Public\Http\Requests\VerifierDossierPublicRequest;
use Modules\Public\Http\Resources\DossierPublicResource;
use Modules\Public\Support\NomComparateur;
use Modules\Public\Support\VerificationEchecsLimiteur;
use Modules\Stagiaires\Models\Stagiaire;

class DossierPublicController extends Controller
{
    private const PORTEE = 'dossier';

    public function __construct(private readonly VerificationEchecsLimiteur $limiteur) {}

    /**
     * Vérification du statut d'un dossier par un candidat externe. Le
     * numéro d'accusé de réception (séquentiel, remis sur papier) ne
     * suffit plus seul : le nom du déposant est exigé en second facteur,
     * pour empêcher le moissonnage de tous les dossiers d'une année par
     * simple énumération du numéro. Réponse strictement identique (404
     * générique) que le numéro soit inconnu, le nom erroné, ou le numéro
     * verrouillé après trop d'échecs — jamais de distinction observable.
     */
    public function verifier(VerifierDossierPublicRequest $request): JsonResponse
    {
        $donnees = $request->validated();
        $numero = trim($donnees['numero']);
        $nomSaisi = trim($donnees['nom']);

        if ($this->limiteur->estVerrouille(self::PORTEE, $numero)) {
            return $this->reponseEchec();
        }

        $courrier = Courrier::query()->where('numero_accuse_reception', $numero)->first();

        if (! $courrier || ! $this->secondFacteurCorrespond($courrier, $nomSaisi)) {
            $this->limiteur->enregistrerEchec(self::PORTEE, $numero, 'public.dossier.verification_echouee', $courrier);

            return $this->reponseEchec();
        }

        $this->limiteur->reinitialiser(self::PORTEE, $numero);

        $stagiaire = Stagiaire::query()->where('courrier_id', $courrier->id)->first();

        return (new DossierPublicResource($courrier, $stagiaire))->response();
    }

    private function secondFacteurCorrespond(Courrier $courrier, string $saisie): bool
    {
        $estDemandeStage = $courrier->type === CourrierType::DEMANDE_STAGE;
        $nomStocke = $estDemandeStage ? $courrier->candidat_nom : $courrier->expediteur_externe_nom;
        $emailStocke = $estDemandeStage ? $courrier->candidat_email : $courrier->expediteur_externe_email;

        if (filled($emailStocke) && mb_strtolower($saisie) === mb_strtolower($emailStocke)) {
            return true;
        }

        return NomComparateur::correspond($saisie, $nomStocke);
    }

    private function reponseEchec(): JsonResponse
    {
        return response()->json(['message' => 'Aucun dossier ne correspond à ces informations.'], 404);
    }
}
