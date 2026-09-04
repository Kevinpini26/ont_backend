<?php

use Illuminate\Support\Facades\Route;
use Modules\Courrier\Http\Controllers\Api\CourrierAnnotationController;
use Modules\Courrier\Http\Controllers\Api\CourrierController;
use Modules\Courrier\Http\Controllers\Api\CourrierEnSouffranceController;
use Modules\Courrier\Http\Controllers\Api\CourrierRattrapageNumerisationController;
use Modules\Courrier\Http\Controllers\Api\CourrierStatistiqueController;
use Modules\Courrier\Http\Controllers\Api\DelegationPosteController;
use Modules\Courrier\Http\Controllers\Api\EmpruntOriginalController;
use Modules\Courrier\Http\Controllers\Api\RegistreCourrierController;

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('/courriers/statistiques', [CourrierStatistiqueController::class, 'index']);
    Route::get('/courriers/statistiques-dg', [CourrierStatistiqueController::class, 'dg']);
    Route::get('/courriers/statistiques-direction', [CourrierStatistiqueController::class, 'pourDirection']);
    Route::get('/courriers/registre', [RegistreCourrierController::class, 'telecharger']);
    Route::get('/courriers/en-souffrance', [CourrierEnSouffranceController::class, 'index']);
    Route::get('/courriers/a-numeriser', [CourrierRattrapageNumerisationController::class, 'index']);
    Route::get('/delegations-poste', [DelegationPosteController::class, 'index']);
    Route::post('/delegations-poste', [DelegationPosteController::class, 'store']);
    Route::get('/courriers/export', [CourrierController::class, 'export']);
    Route::get('/courriers/feuilles-couverture-lot', [CourrierController::class, 'feuilleCouvertureLot']);
    Route::post('/courriers/import-lot', [CourrierController::class, 'importerLot']);
    Route::get('/courriers/originaux-empruntes', [EmpruntOriginalController::class, 'index']);
    Route::get('/courriers', [CourrierController::class, 'index']);
    Route::post('/courriers', [CourrierController::class, 'store']);
    Route::post('/courriers/initier-dg', [CourrierController::class, 'initierParDg']);
    Route::post('/courriers/initier-sortant', [CourrierController::class, 'initierSortant']);
    Route::get('/courriers/{courrier}', [CourrierController::class, 'show']);

    Route::post('/courriers/{courrier}/accuser-reception', [CourrierController::class, 'accuserReception']);
    Route::post('/courriers/{courrier}/imputer', [CourrierController::class, 'imputer']);
    Route::post('/courriers/{courrier}/initier-reponse', [CourrierController::class, 'initierReponse']);
    Route::post('/courriers/{courrier}/envoyer', [CourrierController::class, 'envoyer']);
    Route::post('/courriers/{courrier}/enregistrer-remise', [CourrierController::class, 'enregistrerRemise']);
    Route::post('/courriers/{courrier}/transmettre-protocole', [CourrierController::class, 'transmettreProtocole']);
    Route::post('/courriers/{courrier}/transmettre-tri', [CourrierController::class, 'transmettreTri']);
    Route::post('/courriers/{courrier}/transmettre-au-tri-depuis-protocole', [CourrierController::class, 'transmettreAuTriDepuisProtocole']);
    Route::post('/courriers/{courrier}/valider-avant-diffusion', [CourrierController::class, 'validerAvantDiffusion']);
    Route::post('/courriers/{courrier}/transmettre-avis-dg', [CourrierController::class, 'transmettreAvisDg']);
    Route::post('/courriers/{courrier}/representer-dg', [CourrierController::class, 'representerDg']);
    Route::post('/courriers/{courrier}/dispatcher-direction', [CourrierController::class, 'dispatcherVersDirection']);
    Route::post('/courriers/{courrier}/requalifier-urgence', [CourrierController::class, 'requalifierUrgence']);
    Route::post('/courriers/{courrier}/rendre-avis', [CourrierController::class, 'rendreAvis']);
    Route::post('/courriers/{courrier}/soumettre-projet-reponse', [CourrierController::class, 'soumettreProjetReponse']);
    Route::post('/courriers/{courrier}/valider-relecture', [CourrierController::class, 'validerRelecture']);
    Route::post('/courriers/{courrier}/signer', [CourrierController::class, 'signer']);
    Route::post('/courriers/{courrier}/enregistrer', [CourrierController::class, 'enregistrer']);
    Route::get('/courriers/{courrier}/pdf', [CourrierController::class, 'telechargerPdf']);
    Route::get('/courriers/{courrier}/imprimer', [CourrierController::class, 'imprimer']);
    Route::post('/courriers/{courrier}/jeton-capture', [CourrierController::class, 'genererJetonCapture']);
    Route::get('/courriers/{courrier}/feuille-couverture', [CourrierController::class, 'feuilleCouverture']);
    Route::post('/courriers/{courrier}/sortir-original', [CourrierController::class, 'sortirOriginal']);
    Route::post('/courriers/{courrier}/restituer-original', [CourrierController::class, 'restituerOriginal']);
    Route::get('/courriers/{courrier}/lettre-stage', [CourrierController::class, 'telechargerLettreStage']);
    Route::get('/courriers/{courrier}/pieces/{piece}', [CourrierController::class, 'telechargerPieceCandidat']);
    Route::get('/courriers/{courrier}/piece-jointe', [CourrierController::class, 'telechargerPieceJointe']);
    Route::get('/courriers/{courrier}/pieces-jointes/{piece}', [CourrierController::class, 'telechargerPiece']);

    Route::get('/courriers/{courrier}/annotations', [CourrierAnnotationController::class, 'index']);
    Route::post('/courriers/{courrier}/annotations', [CourrierAnnotationController::class, 'store']);
});
