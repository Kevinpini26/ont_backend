<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Modules\Courrier\Contracts\NumeroGenerator;
use Modules\Courrier\Enums\CourrierClassification;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Services\CourrierCircuitService;
use Modules\Courrier\Services\DocumentProduitDirectionService;
use Modules\Courrier\Services\ReferenceDocumentaireService;
use Modules\Kernel\Models\User;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->environment() !== 'testing' || $app['config']->get('database.connections.pgsql.database') !== 'ont_testing') {
    fwrite(STDERR, 'Concurrent identity worker refuses any non-ont_testing database.');
    exit(2);
}

[$script, $mode, $identifiant, $acteurId, $barriere, $worker] = $argv;
file_put_contents($barriere.'/ready-'.$worker, 'ready');
$limite = microtime(true) + 60;
while (! file_exists($barriere.'/start') && microtime(true) < $limite) {
    usleep(10_000);
}

try {
    $valeur = match ($mode) {
        'numero' => app(NumeroGenerator::class)->genererNumeroEnregistrement(),
        'depot' => app(CourrierCircuitService::class)->enregistrer(
            Courrier::withoutGlobalScopes()->findOrFail((int) $identifiant),
            User::query()->findOrFail((int) $acteurId),
            CourrierClassification::EXTERNE,
            null,
            'Dépôt public reçu',
        )->numero_enregistrement,
        'document-interne' => app(DocumentProduitDirectionService::class)->valider(
            DocumentProduitDirection::query()->findOrFail((int) $identifiant),
            User::query()->findOrFail((int) $acteurId),
        )->courrier->reference_documentaire,
        'autonome-concurrent' => $worker === '0'
            ? app(DocumentProduitDirectionService::class)->modifierBrouillon(
                DocumentProduitDirection::query()->findOrFail((int) $identifiant),
                User::query()->findOrFail((int) $acteurId),
                [
                    'version_contenu' => 1,
                    'objet' => 'Sauvegarde concurrente',
                    'contenu' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Nouvelle version.']]]]],
                ],
            )->version_contenu
            : app(DocumentProduitDirectionService::class)->soumettre(
                DocumentProduitDirection::query()->findOrFail((int) $identifiant),
                User::query()->findOrFail((int) $acteurId),
                ['version_contenu' => 1],
            )->statut->value,
        'autonome-sauvegarde' => app(DocumentProduitDirectionService::class)->modifierBrouillon(
            DocumentProduitDirection::query()->findOrFail((int) $identifiant),
            User::query()->findOrFail((int) $acteurId),
            [
                'version_contenu' => 1,
                'objet' => "Sauvegarde worker {$worker}",
                'contenu' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "Texte worker {$worker}."]]]]],
            ],
        )->version_contenu,
        'autonome-validation' => app(DocumentProduitDirectionService::class)->valider(
            DocumentProduitDirection::query()->findOrFail((int) $identifiant),
            User::query()->findOrFail((int) $acteurId),
        )->courrier->reference_documentaire,
        'autonome-correction-validation' => $worker === '0'
            ? app(DocumentProduitDirectionService::class)->demanderCorrection(
                DocumentProduitDirection::query()->findOrFail((int) $identifiant),
                User::query()->findOrFail((int) $acteurId),
                'Correction concurrente.',
            )->statut->value
            : app(DocumentProduitDirectionService::class)->valider(
                DocumentProduitDirection::query()->findOrFail((int) $identifiant),
                User::query()->findOrFail((int) $acteurId),
            )->courrier->reference_documentaire,
        default => app(ReferenceDocumentaireService::class)->attribuer(
            Courrier::withoutGlobalScopes()->findOrFail((int) $identifiant),
            User::query()->findOrFail((int) $acteurId),
            2026,
        )->reference_documentaire,
    };
    file_put_contents($barriere.'/result-'.$worker.'.json', json_encode(['status' => 'ok', 'value' => $valeur], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    file_put_contents($barriere.'/result-'.$worker.'.json', json_encode(['status' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR));
}
