<?php

namespace Modules\Kernel\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Http\Requests\LoginRequest;
use Modules\Kernel\Http\Resources\UserResource;
use Modules\Kernel\Models\User;

class AuthController extends Controller
{
    private const SEUIL_ECHECS = 5;

    private const DUREE_VERROUILLAGE_MINUTES = 15;

    public function __construct(private readonly AuditLogger $audit) {}

    public function login(LoginRequest $request)
    {
        $credentials = $request->validated();
        $email = mb_strtolower($credentials['email']);

        $utilisateur = User::query()->where('email', $email)->first();

        // Verrouillage par compte, distinct du limiteur de débit 'auth'
        // (par IP/e-mail, fenêtre glissante d'une minute — voir
        // AppServiceProvider) : indépendant de l'IP appelante, ne se lève
        // qu'après DUREE_VERROUILLAGE_MINUTES ou une intervention
        // administrative (voir UserController::deverrouiller()).
        if ($utilisateur && $this->estVerrouille($utilisateur)) {
            $this->audit->enregistrer('auth.tentative_compte_verrouille', $utilisateur, contexte: ['email' => $email]);

            throw ValidationException::withMessages([
                'email' => ['Ce compte est temporairement verrouillé après plusieurs échecs de connexion. Réessayez dans quelques minutes.'],
            ]);
        }

        if (! Auth::attempt(['email' => $email, 'password' => $credentials['password']])) {
            // On ne journalise jamais le mot de passe fourni, uniquement
            // l'e-mail tenté, utile pour détecter un bruteforce ciblé.
            $this->audit->enregistrer('auth.echec_connexion', contexte: ['email' => $email]);

            if ($utilisateur) {
                $this->enregistrerEchec($utilisateur);
            }

            throw ValidationException::withMessages([
                'email' => ["Les identifiants fournis sont incorrects."],
            ]);
        }

        /** @var User $utilisateur */
        $utilisateur = Auth::user();
        $this->reinitialiserEchecs($utilisateur);

        $expiration = config('sanctum.expiration');
        $expiresAt = $expiration ? Carbon::now()->addMinutes((int) $expiration) : null;
        $token = $utilisateur->createToken(
            $credentials['device_name'] ?? 'api',
            expiresAt: $expiresAt,
        )->plainTextToken;

        $this->audit->enregistrer('auth.connexion', $utilisateur, $utilisateur);

        return response()->json([
            'user' => new UserResource($utilisateur->load('direction')),
            'token' => $token,
            // Permet au frontend de programmer l'avertissement puis la
            // déconnexion automatique avant expiration (voir
            // useSessionExpiryWatcher côté React) — un jeton Sanctum, à la
            // différence d'un JWT, ne porte aucune information
            // d'expiration lisible côté client.
            'expires_at' => $expiresAt?->toIso8601String(),
        ]);
    }

    public function logout(Request $request)
    {
        $this->audit->enregistrer('auth.deconnexion', $request->user(), $request->user());

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnecté avec succès.']);
    }

    public function me(Request $request)
    {
        return new UserResource($request->user()->load('direction'));
    }

    private function estVerrouille(User $utilisateur): bool
    {
        return $utilisateur->verrouille_jusqu_a !== null && $utilisateur->verrouille_jusqu_a->isFuture();
    }

    private function enregistrerEchec(User $utilisateur): void
    {
        $utilisateur->echecs_connexion_consecutifs++;

        if ($utilisateur->echecs_connexion_consecutifs >= self::SEUIL_ECHECS) {
            $utilisateur->verrouille_jusqu_a = now()->addMinutes(self::DUREE_VERROUILLAGE_MINUTES);
            $utilisateur->save();

            $this->audit->enregistrer('auth.compte_verrouille', $utilisateur, contexte: [
                'echecs' => $utilisateur->echecs_connexion_consecutifs,
                'duree_minutes' => self::DUREE_VERROUILLAGE_MINUTES,
            ]);

            return;
        }

        $utilisateur->save();
    }

    private function reinitialiserEchecs(User $utilisateur): void
    {
        if ($utilisateur->echecs_connexion_consecutifs === 0 && $utilisateur->verrouille_jusqu_a === null) {
            return;
        }

        $utilisateur->echecs_connexion_consecutifs = 0;
        $utilisateur->verrouille_jusqu_a = null;
        $utilisateur->save();
    }
}
