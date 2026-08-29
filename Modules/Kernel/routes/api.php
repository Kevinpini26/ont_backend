<?php

use Illuminate\Support\Facades\Route;
use Modules\Kernel\Http\Controllers\Api\AgentCircuitCourrierController;
use Modules\Kernel\Http\Controllers\Api\AuditLogController;
use Modules\Kernel\Http\Controllers\Api\AuthController;
use Modules\Kernel\Http\Controllers\Api\DgDisponibiliteController;
use Modules\Kernel\Http\Controllers\Api\DirectionController;
use Modules\Kernel\Http\Controllers\Api\NotificationCompteurController;
use Modules\Kernel\Http\Controllers\Api\NotificationController;
use Modules\Kernel\Http\Controllers\Api\PasswordController;
use Modules\Kernel\Http\Controllers\Api\RapportPeriodiqueController;
use Modules\Kernel\Http\Controllers\Api\UserController;
use Modules\Kernel\Http\Middleware\EnsureMotDePasseAJour;

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:auth');

    // Mot de passe oublié : jamais authentifié par définition. Même
    // limiteur que le login, pour la même raison (bruteforce/spam d'e-mails).
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/auth/mot-de-passe/oublie', [PasswordController::class, 'envoyerLienReinitialisation']);
        Route::post('/auth/mot-de-passe/reinitialiser', [PasswordController::class, 'reinitialiser']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        // Routes volontairement exemptées du blocage
        // "doit_changer_mot_de_passe" (voir bootstrap/app.php) : se
        // déconnecter, savoir qui on est, et changer son mot de passe
        // doivent rester possibles pendant ce blocage.
        Route::withoutMiddleware(EnsureMotDePasseAJour::class)->group(function () {
            Route::post('/auth/logout', [AuthController::class, 'logout']);
            Route::get('/auth/me', [AuthController::class, 'me']);
            Route::post('/auth/mot-de-passe/changer', [PasswordController::class, 'changer']);
        });

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/marquer-toutes-lues', [NotificationController::class, 'marquerToutesLues']);
        Route::post('/notifications/{id}/marquer-lu', [NotificationController::class, 'marquerLu']);

        Route::get('/notifications/compteurs', [NotificationCompteurController::class, 'index']);
        Route::post('/notifications/marquer-consulte', [NotificationCompteurController::class, 'marquerConsulte']);

        Route::get('/agents-circuit-courrier', [AgentCircuitCourrierController::class, 'index']);

        Route::get('/directions', [DirectionController::class, 'index']);
        Route::get('/directions/{direction}', [DirectionController::class, 'show']);

        Route::get('/dg-disponibilite', [DgDisponibiliteController::class, 'show']);
        Route::post('/dg-disponibilite', [DgDisponibiliteController::class, 'update']);

        // Hors du groupe role:administrateur ci-dessous : accessible aussi à
        // la DFP, voir Gate::genererRapportTutelle (seul vrai contrôle
        // d'accès de cette route).
        Route::get('/rapports/periodique', [RapportPeriodiqueController::class, 'genererPdf']);

        Route::middleware('role:administrateur')->group(function () {
            Route::post('/directions', [DirectionController::class, 'store']);
            Route::put('/directions/{direction}', [DirectionController::class, 'update']);
            Route::patch('/directions/{direction}', [DirectionController::class, 'update']);
            Route::delete('/directions/{direction}', [DirectionController::class, 'destroy']);

            Route::apiResource('users', UserController::class);
            Route::delete('/users/{user}/tokens', [UserController::class, 'revoquerJetons']);
            Route::post('/users/{user}/deverrouiller', [UserController::class, 'deverrouiller']);

            Route::get('/audit-logs', [AuditLogController::class, 'index']);
        });
    });
});
