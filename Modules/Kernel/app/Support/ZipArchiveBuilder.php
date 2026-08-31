<?php

namespace Modules\Kernel\Support;

use RuntimeException;
use ZipArchive;

/**
 * Construit une archive ZIP en mémoire à partir d'un ensemble de fichiers
 * déjà en contenu binaire (jamais de chemin sur disque) — utilisé pour les
 * exports d'archive annuelle combinant plusieurs documents déjà générés
 * (registre courrier, rapport annuel stagiaires...). ZipArchive n'expose
 * pas d'écriture directement en mémoire : passage obligé par un fichier
 * temporaire, supprimé aussitôt son contenu lu.
 */
class ZipArchiveBuilder
{
    /**
     * @param  array<string, string>  $entrees  Nom de fichier dans l'archive => contenu binaire.
     */
    public static function construire(array $entrees): string
    {
        $cheminTemporaire = tempnam(sys_get_temp_dir(), 'ont_archive_');

        $zip = new ZipArchive;
        if ($zip->open($cheminTemporaire, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Impossible de créer l'archive ZIP temporaire.");
        }

        foreach ($entrees as $nomFichier => $contenu) {
            $zip->addFromString($nomFichier, $contenu);
        }

        $zip->close();

        $contenuZip = file_get_contents($cheminTemporaire);
        unlink($cheminTemporaire);

        return $contenuZip;
    }
}
