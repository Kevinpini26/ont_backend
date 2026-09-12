<?php

use Illuminate\Support\Facades\Route;
use Modules\Stagiaires\Http\Controllers\Api\ArchiveAnnuelleController;
use Modules\Stagiaires\Http\Controllers\Api\DisponibiliteDemandesStageController;
use Modules\Stagiaires\Http\Controllers\Api\EtablissementFormationController;
use Modules\Stagiaires\Http\Controllers\Api\ImportHistoriqueController;
use Modules\Stagiaires\Http\Controllers\Api\NotificationDiffusionController;
use Modules\Stagiaires\Http\Controllers\Api\RapportAnnuelController;
use Modules\Stagiaires\Http\Controllers\Api\StagiaireController;
use Modules\Stagiaires\Http\Controllers\Api\StagiaireDocumentController;
use Modules\Stagiaires\Http\Controllers\Api\StagiaireEnSouffranceController;
use Modules\Stagiaires\Http\Controllers\Api\StagiairePresenceController;
use Modules\Stagiaires\Http\Controllers\Api\StagiaireStatistiqueController;
use Modules\Stagiaires\Http\Controllers\Api\StagiaireSuiviController;
use Modules\Stagiaires\Http\Controllers\Api\TableauRepartitionController;

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::middleware('role:administrateur')->group(function () {
        Route::post('/admin/stagiaires/import-historique', [ImportHistoriqueController::class, 'importer']);
    });

    Route::get('/stagiaires/statistiques', [StagiaireStatistiqueController::class, 'index']);
    Route::get('/stagiaires/alertes', [StagiaireStatistiqueController::class, 'alertes']);
    Route::get('/stagiaires/en-souffrance', [StagiaireEnSouffranceController::class, 'index']);
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
    // Plus de route directe "/affecter" (Lot 5) : l'affectation réelle
    // n'est plus qu'un effet de l'approbation d'un tableau de répartition
    // (voir TableauRepartitionCircuitService::rendreAvis()).
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
    Route::get('/stagiaires/{stagiaire}/imprimer', [StagiaireController::class, 'imprimer']);
    Route::post('/stagiaires/{stagiaire}/jeton-capture', [StagiaireController::class, 'genererJetonCapture']);
    Route::get('/stagiaires/{stagiaire}/retour', [StagiaireController::class, 'retour']);

    Route::get('/stagiaires/{stagiaire}/presences', [StagiairePresenceController::class, 'index']);
    Route::post('/stagiaires/{stagiaire}/presences', [StagiairePresenceController::class, 'store']);
    Route::post('/stagiaires/{stagiaire}/presences/reconciliation', [StagiairePresenceController::class, 'reconciliation']);
    Route::delete('/stagiaires/{stagiaire}/presences/{date}', [StagiairePresenceController::class, 'destroy'])
        ->where('date', '\d{4}-\d{2}-\d{2}');

    Route::get('/stagiaires/{stagiaire}/suivis', [StagiaireSuiviController::class, 'index']);
    Route::post('/stagiaires/{stagiaire}/suivis', [StagiaireSuiviController::class, 'store']);

    Route::get('/stagiaires/{stagiaire}/documents', [StagiaireDocumentController::class, 'index']);
    Route::post('/stagiaires/{stagiaire}/documents', [StagiaireDocumentController::class, 'store'])
        ->middleware('throttle:sensitive');
    Route::get('/stagiaires/{stagiaire}/documents/{document}/telecharger', [StagiaireDocumentController::class, 'download']);

    // Lot 4 — tableau de répartition.
    Route::get('/tableaux-repartition', [TableauRepartitionController::class, 'index']);
    Route::post('/tableaux-repartition', [TableauRepartitionController::class, 'store']);
    Route::get('/tableaux-repartition/dossiers-eligibles', [TableauRepartitionController::class, 'dossiersEligibles']);
    Route::get('/tableaux-repartition/motifs-non-retenu', [TableauRepartitionController::class, 'motifsNonRetenu']);
    Route::get('/tableaux-repartition/{tableau}', [TableauRepartitionController::class, 'show']);
    Route::post('/tableaux-repartition/{tableau}/lignes', [TableauRepartitionController::class, 'ajouterLigne']);
    Route::post('/tableaux-repartition/{tableau}/lignes/lot', [TableauRepartitionController::class, 'ajouterLignesEnLot']);
    Route::delete('/tableaux-repartition/{tableau}/lignes/{ligne}', [TableauRepartitionController::class, 'retirerLigne']);
    Route::post('/tableaux-repartition/{tableau}/soumettre', [TableauRepartitionController::class, 'soumettre']);
    Route::post('/tableaux-repartition/{tableau}/representer-dg', [TableauRepartitionController::class, 'representerDg']);
    Route::post('/tableaux-repartition/{tableau}/rendre-avis', [TableauRepartitionController::class, 'rendreAvis']);
    Route::get('/tableaux-repartition/{tableau}/pdf', [TableauRepartitionController::class, 'telechargerPdf']);

    // Lot B — suivi des notifications de diffusion.
    Route::get('/stagiaires/{stagiaire}/notifications-diffusion', [NotificationDiffusionController::class, 'index']);
    Route::post('/notifications-diffusion/{notification}/renvoyer', [NotificationDiffusionController::class, 'renvoyer']);
});
