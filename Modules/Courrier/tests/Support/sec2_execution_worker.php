<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Exceptions\TransitionNonAutoriseeException;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Courrier\Models\Dossier;
use Modules\Courrier\Services\ArchivageDossierService;
use Modules\Courrier\Services\ClassementDocumentService;
use Modules\Courrier\Services\CourrierCircuitService;
use Modules\Courrier\Services\DispatchCourrierService;
use Modules\Kernel\Models\User;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $action, $id, $acteurId, $barriere, $worker] = $argv;
file_put_contents($barriere.'/ready-'.$worker, 'ready');
$limite = microtime(true) + 15;
while (! file_exists($barriere.'/start') && microtime(true) < $limite) {
    usleep(10_000);
}
try {
    $acteur = User::query()->findOrFail((int) $acteurId);
    match ($action) {
        'dispatch' => app(DispatchCourrierService::class)->executer(DispatchCourrier::query()->findOrFail((int) $id), $acteur),
        'classement' => app(ClassementDocumentService::class)->classer(DispatchCourrier::query()->findOrFail((int) $id), $acteur, ['emplacement' => 'Archives concurrence']),
        'archive' => app(ArchivageDossierService::class)->archiver(Dossier::query()->findOrFail((int) $id), $acteur),
        'envoi' => app(CourrierCircuitService::class)->envoyer(Courrier::withoutGlobalScopes()->findOrFail((int) $id), $acteur, ['destinataire_externe_nom' => 'Partenaire signé', 'mode_expedition' => 'poste']),
        default => throw new LogicException('Action de test inconnue.'),
    };
    $resultat = ['status' => 'ok'];
} catch (ValidationException|TransitionNonAutoriseeException $exception) {
    $resultat = ['status' => 'refused'];
} catch (Throwable $exception) {
    $resultat = ['status' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()];
}
file_put_contents($barriere.'/result-'.$worker.'.json', json_encode($resultat, JSON_THROW_ON_ERROR));
