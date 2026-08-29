<?php

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Kernel\Http\Middleware\EnsureMotDePasseAJour;
use Modules\Kernel\Http\Middleware\EnsureUserHasPoste;
use Modules\Kernel\Http\Middleware\EnsureUserHasRole;
use Modules\Kernel\Http\Middleware\SecurityHeaders;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'poste' => EnsureUserHasPoste::class,
            'doit-changer-mot-de-passe' => EnsureMotDePasseAJour::class,
        ]);

        // Appliqué à toute l'API : n'a d'effet que pour un utilisateur
        // authentifié dont doit_changer_mot_de_passe est levé (voir
        // EnsureMotDePasseAJour) — sans effet sur les routes publiques ou
        // le login. Exclu explicitement des routes qui doivent rester
        // accessibles pendant ce blocage (déconnexion, profil, changement
        // de mot de passe) via ->withoutMiddleware() sur ces routes.
        $middleware->api(append: [EnsureMotDePasseAJour::class, SecurityHeaders::class]);

        // API pure : jamais de redirection vers une route "login" (qui
        // n'existe pas), toujours une exception JSON 401.
        Authenticate::redirectUsing(fn () => null);

        // Rate limiting général sur toutes les routes /api/* (voir le
        // limiteur 'api' défini dans AppServiceProvider) ; des limiteurs
        // plus stricts ('auth', 'sensitive') sont appliqués route par
        // route là où le risque de bruteforce/énumération est le plus élevé.
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Voir SecurityHeaders::appliquer() : seul point qui couvre aussi
        // bien les réponses normales que les erreurs.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            return $request->is('api/*') ? SecurityHeaders::appliquer($response, $request) : $response;
        });
    })->create();
