<?php

use Illuminate\Support\Facades\Route;
use Modules\Stagiaires\Http\Controllers\Api\ArchiveAnnuelleController;
use Modules\Stagiaires\Http\Controllers\Api\DisponibiliteDemandesStageController;
use Modules\Stagiaires\Http\Controllers\Api\EtablissementFormationController;
use Modules\Stagiaires\Http\Controllers\Api\ImportHistoriqueController;
use Modules\Stagiaires\Http\Controllers\Api\RapportAnnuelController;
use Modules\Stagiaires\Http\Controllers\Api\StagiaireController;
use Modules\Stagiaires\Http\Controllers\Api\StagiaireDocumentController;
use Modules\Stagiaires\Http\Controllers\Api\StagiairePresenceController;
use Modules\Stagiaires\Http\Controllers\Api\StagiaireStatistiqueController;
use Modules\Stagiaires\Http\Controllers\Api\StagiaireSuiviController;

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::middleware('role:administrateur')->group(function () {
        Route::post('/admin/stagiaires/import-historique', [ImportHistoriqueController::class, 'importer']);
    });

    Route::get('/stagiaires/statistiques', [StagiaireStatistiqueController::class, 'index']);
    Route::get('/stagiaires/alertes', [StagiaireStatistiqueController::class, 'alertes']);
    Route::get('/stagiaires/export', [StagiaireController::class, 'export']);
    Route::get('/stagiaires/rapport-annuel', [RapportAnnuelController::class, 'telecharger']);
    Route::get('/archives/annuelle', [ArchiveAnnuelleController::class, 'telecharger']);
    Route::get('/stagiaires/disponibilite-demandes', [DisponibiliteDemandesStageController::class, 'show']);
    Route::post('/stagiaires/disponibilite-demandes', [DisponibiliteDemandesStageController::class, 'update']);
    Route::get('/etablissements-formation', [EtablissementFormationController::class, 'index']);
    Route::post('/etablissements-formation', [EtablissementFormationController::class, 'store']);
    Route::get('/stagiaires', [StagiaireController::class, 'index']);
    Route::get('/stagiaires/{stagiaire}', [StagiaireController::class, 'show']);

    Route::post('/stagiaires/{stagiaire}/examiner-dossier', [StagiaireController::class, 'examinerDossier']);
    Route::post('/stagiaires/{stagiaire}/affecter', [StagiaireController::class, 'affecter']);
    Route::post('/stagiaires/{stagiaire}/reaffecter', [StagiaireController::class, 'reaffecter']);
    Route::post('/stagiaires/{stagiaire}/valider-arrivee', [StagiaireController::class, 'validerArrivee']);
    Route::post('/stagiaires/{stagiaire}/terminer-stage', [StagiaireController::class, 'terminerStage']);
    Route::post('/stagiaires/{stagiaire}/modifier-dates', [StagiaireController::class, 'modifierDatesStage']);
    Route::post('/stagiaires/{stagiaire}/prolonger', [StagiaireController::class, 'prolonger']);
    Route::post('/stagiaires/{stagiaire}/evaluer-direction', [StagiaireController::class, 'evaluerDirection']);
    Route::post('/stagiaires/{stagiaire}/evaluer-dfp', [StagiaireController::class, 'evaluerDfp']);
    Route::post('/stagiaires/{stagiaire}/ouvrir-periode-evaluation', [StagiaireController::class, 'ouvrirPeriodeEvaluation']);
    Route::post('/stagiaires/{stagiaire}/objectifs', [StagiaireController::class, 'definirObjectifs']);
    Route::post('/stagiaires/{stagiaire}/informations-complementaires', [StagiaireController::class, 'definirInformationsComplementaires']);
    Route::post('/stagiaires/{stagiaire}/convention/signer-direction', [StagiaireController::class, 'signerConventionDirection']);
    Route::get('/stagiaires/{stagiaire}/convention/telecharger', [StagiaireController::class, 'telechargerConvention']);
    Route::get('/stagiaires/{stagiaire}/badge', [StagiaireController::class, 'badge']);
    Route::get('/stagiaires/{stagiaire}/retour', [StagiaireController::class, 'retour']);

    Route::get('/stagiaires/{stagiaire}/presences', [StagiairePresenceController::class, 'index']);
    Route::post('/stagiaires/{stagiaire}/presences', [StagiairePresenceController::class, 'store']);
    Route::delete('/stagiaires/{stagiaire}/presences/{date}', [StagiairePresenceController::class, 'destroy'])
        ->where('date', '\d{4}-\d{2}-\d{2}');

    Route::get('/stagiaires/{stagiaire}/suivis', [StagiaireSuiviController::class, 'index']);
    Route::post('/stagiaires/{stagiaire}/suivis', [StagiaireSuiviController::class, 'store']);

    Route::get('/stagiaires/{stagiaire}/documents', [StagiaireDocumentController::class, 'index']);
    Route::post('/stagiaires/{stagiaire}/documents', [StagiaireDocumentController::class, 'store'])
        ->middleware('throttle:sensitive');
    Route::get('/stagiaires/{stagiaire}/documents/{document}/telecharger', [StagiaireDocumentController::class, 'download']);
});
