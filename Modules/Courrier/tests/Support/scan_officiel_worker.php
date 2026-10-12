<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Services\DocumentProduitScanOfficielService;
use Modules\Kernel\Models\User;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[, $documentId, $deposantId, $cheminFichier, $cle, $barriere, $worker] = $argv;
file_put_contents($barriere.'/ready-'.$worker, 'ready');
$limite = microtime(true) + 15;
while (! file_exists($barriere.'/start') && microtime(true) < $limite) {
    usleep(10_000);
}

try {
    $document = DocumentProduitDirection::query()->findOrFail((int) $documentId);
    $deposant = User::query()->findOrFail((int) $deposantId);
    $fichier = new UploadedFile($cheminFichier, 'scan.pdf', 'application/pdf', null, true);
    $resultat = app(DocumentProduitScanOfficielService::class)->deposer($document, $deposant, $fichier, $cle);
    $response = ['status' => $resultat['created'] ? 'created' : 'replayed'];
} catch (ConflictHttpException) {
    $response = ['status' => 'conflict'];
} catch (Throwable $exception) {
    $response = ['status' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()];
}

file_put_contents($barriere.'/result-'.$worker.'.json', json_encode($response, JSON_THROW_ON_ERROR));
