<?php

namespace Modules\Kernel\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Http\Requests\ChangerMotDePasseRequest;
use Modules\Kernel\Http\Requests\DemanderReinitialisationMotDePasseRequest;
use Modules\Kernel\Http\Requests\ReinitialiserMotDePasseRequest;
use Modules\Kernel\Models\User;

class PasswordController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Changement volontaire par l'utilisateur connecté : révoque tous les
     * autres jetons du compte (voir UserController::revoquerJetons() pour
     * le même geste déclenché par un administrateur), garde le jeton
     * courant pour ne pas déconnecter la session qui vient de faire la
     * demande.
     */
    public function changer(ChangerMotDePasseRequest $request): JsonResponse
    {
        $utilisateur = $request->user();
        $donnees = $request->validated();

        if (! Hash::check($donnees['ancien_mot_de_passe'], $utilisateur->password)) {
            throw ValidationException::withMessages([
                'ancien_mot_de_passe' => ["L'ancien mot de passe est incorrect."],
            ]);
        }

        $utilisateur->password = $donnees['mot_de_passe'];
        $utilisateur->doit_changer_mot_de_passe = false;
        $utilisateur->save();

        $utilisateur->tokens()->where('id', '!=', $utilisateur->currentAccessToken()->id)->delete();

        $this->audit->enregistrer('auth.mot_de_passe_change', $utilisateur, $utilisateur);

        return response()->json(['message' => 'Mot de passe changé avec succès.']);
    }

    /**
     * Réponse strictement identique que l'e-mail corresponde ou non à un
     * compte : voir la même discipline appliquée aux vérifications
     * publiques de dossier/attestation — ne jamais confirmer par la
     * réponse elle-même qu'une adresse est enregistrée.
     */
    public function envoyerLienReinitialisation(DemanderReinitialisationMotDePasseRequest $request): JsonResponse
    {
        Password::broker()->sendResetLink($request->only('email'));

        return response()->json([
            'message' => "Si cette adresse correspond à un compte, un e-mail de réinitialisation vient d'être envoyé.",
        ]);
    }

    public function reinitialiser(ReinitialiserMotDePasseRequest $request): JsonResponse
    {
        $donnees = $request->validated();

        $statut = Password::broker()->reset(
            [
                'email' => $donnees['email'],
                'token' => $donnees['token'],
                'password' => $donnees['mot_de_passe'],
            ],
            function (User $utilisateur, string $motDePasse) {
                $utilisateur->password = $motDePasse;
                $utilisateur->doit_changer_mot_de_passe = false;
                $utilisateur->echecs_connexion_consecutifs = 0;
                $utilisateur->verrouille_jusqu_a = null;
                $utilisateur->save();

                $utilisateur->tokens()->delete();

                $this->audit->enregistrer('auth.mot_de_passe_reinitialise', $utilisateur, $utilisateur);
            },
        );

        if ($statut !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => ['Ce lien de réinitialisation est invalide ou a expiré.'],
            ]);
        }

        return response()->json(['message' => 'Mot de passe réinitialisé avec succès.']);
    }
}
