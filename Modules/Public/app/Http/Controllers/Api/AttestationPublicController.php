<?php

namespace Modules\Public\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Public\Http\Requests\VerifierAttestationPublicRequest;
use Modules\Public\Http\Resources\AttestationPublicResource;
use Modules\Public\Support\NomComparateur;
use Modules\Public\Support\VerificationEchecsLimiteur;
use Modules\Stagiaires\Models\Stagiaire;

class AttestationPublicController extends Controller
{
    private const PORTEE = 'attestation';

    public function __construct(private readonly VerificationEchecsLimiteur $limiteur) {}

    /**
     * Vérification par le jeton encodé dans le QR code des attestations
     * émises depuis l'introduction du jeton : 32 caractères aléatoires,
     * non énumérables, donc aucun second facteur n'est exigé ici — au
     * contraire du numéro séquentiel historique (voir verifierParNumero()).
     */
    public function verifierParToken(string $token): JsonResponse
    {
        $stagiaire = Stagiaire::query()->where('token_verification', $token)->first();

        if (! $stagiaire) {
            return $this->reponseEchec();
        }

        return (new AttestationPublicResource($stagiaire))->response();
    }

    /**
     * Voie de secours pour les attestations déjà imprimées avant
     * l'introduction du jeton (QR encodant encore l'ancien numéro
     * séquentiel ATT-AAAA-NNNNNN) : le nom du stagiaire est exigé en second
     * facteur, avec le même verrouillage par identifiant que les dossiers
     * publics.
     */
    public function verifierParNumero(VerifierAttestationPublicRequest $request): JsonResponse
    {
        $donnees = $request->validated();
        $numero = trim($donnees['numero']);
        $nomSaisi = trim($donnees['nom']);

        if ($this->limiteur->estVerrouille(self::PORTEE, $numero)) {
            return $this->reponseEchec();
        }

        $stagiaire = Stagiaire::query()->where('numero_attestation', $numero)->first();

        if (! $stagiaire || ! NomComparateur::correspond($nomSaisi, $stagiaire->nom)) {
            $this->limiteur->enregistrerEchec(self::PORTEE, $numero, 'public.attestation.verification_echouee', $stagiaire);

            return $this->reponseEchec();
        }

        $this->limiteur->reinitialiser(self::PORTEE, $numero);

        return (new AttestationPublicResource($stagiaire))->response();
    }

    private function reponseEchec(): JsonResponse
    {
        return response()->json(['message' => 'Aucune attestation ne correspond à ces informations.'], 404);
    }
}
