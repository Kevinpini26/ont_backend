<?php

namespace Modules\Kernel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloque tout le reste de l'API tant qu'un utilisateur n'a pas changé le
 * mot de passe attribué par l'administrateur (voir
 * User::doit_changer_mot_de_passe, posé à la création/réinitialisation
 * d'un compte par UserController). Appliqué globalement (voir
 * bootstrap/app.php) et explicitement exclu des routes qui doivent rester
 * accessibles pendant ce blocage : se déconnecter, consulter son propre
 * profil, et changer son mot de passe lui-même.
 */
class EnsureMotDePasseAJour
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->doit_changer_mot_de_passe) {
            return response()->json([
                'message' => 'Vous devez changer votre mot de passe avant de continuer.',
                'code' => 'doit_changer_mot_de_passe',
            ], 423);
        }

        return $next($request);
    }
}
