<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Limiteur par défaut de toutes les routes /api/* (voir
        // bootstrap/app.php : $middleware->throttleApi()).
        RateLimiter::for('api', fn ($request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        // Connexion : freine le bruteforce de mots de passe. On limite à la
        // fois par IP et par e-mail tenté pour ne pas bloquer tout un
        // bureau derrière une même IP à cause d'un seul compte attaqué,
        // tout en empêchant un attaquant distribué de tourner sur un seul
        // compte cible.
        RateLimiter::for('auth', function ($request) {
            return [
                Limit::perMinute(10)->by('auth-ip:'.$request->ip()),
                Limit::perMinute(5)->by('auth-email:'.mb_strtolower((string) $request->input('email'))),
            ];
        });

        // Endpoints sensibles authentifiés ou non (upload de documents,
        // vérification publique de dossier) : plus permissif que 'auth'
        // mais plus strict que le débit API général.
        RateLimiter::for('sensitive', fn ($request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));

        // Vérification publique par identifiant séquentiel devinable
        // (numéro de dossier, numéro d'attestation) : freine le
        // moissonnage par IP, en plus du verrouillage par identifiant
        // (voir Modules\Public\Support\VerificationEchecsLimiteur).
        RateLimiter::for('public-lookup', fn ($request) => Limit::perMinute(10)->by($request->ip()));
    }
}
