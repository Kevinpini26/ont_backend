<?php

use Illuminate\Support\Facades\Route;
use Modules\Public\Http\Controllers\Api\AttestationPublicController;
use Modules\Public\Http\Controllers\Api\CourrierExternePublicController;
use Modules\Public\Http\Controllers\Api\DemandeStagePublicController;
use Modules\Public\Http\Controllers\Api\DisponibiliteDemandesStagePublicController;
use Modules\Public\Http\Controllers\Api\DossierPublicController;
use Modules\Public\Http\Controllers\Api\LienPublicController;
use Modules\Public\Http\Controllers\Api\MentionInformationController;
use Modules\Public\Http\Controllers\Api\StatistiquesPubliquesController;

// Aucune authentification : accessible à tout candidat externe muni de son
// numéro d'accusé de réception, ou d'un lien à usage unique.
Route::prefix('v1/public')->middleware('throttle:sensitive')->group(function () {
    Route::post('/demandes-stage', [DemandeStagePublicController::class, 'store']);
    Route::get('/disponibilite-demandes-stage', [DisponibiliteDemandesStagePublicController::class, 'show']);
    Route::get('/statistiques', [StatistiquesPubliquesController::class, 'show']);
    Route::get('/mention-information', [MentionInformationController::class, 'show']);
    Route::post('/courriers-externes', [CourrierExternePublicController::class, 'store']);

    // Vérifications à identifiant devinable (numéro séquentiel) : limiteur
    // dédié par IP en plus de 'sensitive', et verrouillage par identifiant
    // géré dans le contrôleur (voir VerificationEchecsLimiteur).
    Route::middleware('throttle:public-lookup')->group(function () {
        Route::post('/dossiers/verifier', [DossierPublicController::class, 'verifier']);
        Route::get('/attestations/token/{token}', [AttestationPublicController::class, 'verifierParToken']);
        Route::post('/attestations/verifier', [AttestationPublicController::class, 'verifierParNumero']);
    });

    Route::get('/liens/{token}', [LienPublicController::class, 'show']);
    Route::get('/liens/{token}/convention.pdf', [LienPublicController::class, 'telechargerConvention']);
    Route::post('/liens/{token}/signer-convention', [LienPublicController::class, 'signerConvention']);
    Route::post('/liens/{token}/retour', [LienPublicController::class, 'soumettreRetour']);
    Route::post('/liens/{token}/rapport-stage', [LienPublicController::class, 'soumettreRapportStage']);
    Route::post('/liens/{token}/signer-engagement-confidentialite', [LienPublicController::class, 'signerEngagementConfidentialite']);
});
