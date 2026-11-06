<?php

/*
 * Point d'entrée de la suite de tests : donne aux tests un dossier de stockage à eux, vidé au
 * début de chaque lancement. Ainsi, php artisan test n'écrit jamais dans storage/app, où un fichier
 * à nom fixe (note d'affectation, tableau de répartition) écraserait celui d'une vraie base.
 * Les processus lancés par un test héritent de ce dossier par l'environnement.
 */

require __DIR__.'/../vendor/autoload.php';

$stockage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ont-tests-stockage';

if (is_dir($stockage)) {
    $contenu = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stockage, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($contenu as $element) {
        $element->isDir() && ! $element->isLink() ? rmdir($element->getPathname()) : unlink($element->getPathname());
    }
}

foreach (['app/private', 'app/public', 'app/backups', 'framework/cache/data', 'framework/sessions', 'framework/testing', 'framework/views', 'logs'] as $dossier) {
    $chemin = $stockage.DIRECTORY_SEPARATOR.$dossier;

    if (! is_dir($chemin)) {
        mkdir($chemin, 0755, true);
    }
}

$_ENV['LARAVEL_STORAGE_PATH'] = $stockage;
$_SERVER['LARAVEL_STORAGE_PATH'] = $stockage;
putenv('LARAVEL_STORAGE_PATH='.$stockage);
