<?php

namespace Modules\Kernel\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export tableur générique (CSV, ouvrable directement dans tout tableur —
 * Excel, LibreOffice, Google Sheets) réutilisé par tout module qui expose
 * une liste exportable (courriers, stagiaires...). Volontairement CSV et
 * non un .xlsx natif : aucune dépendance de génération de tableur binaire
 * n'était déjà présente dans le projet (voir docs/questions-ont.md) — un
 * export avec mise en forme réelle nécessiterait d'ajouter une librairie
 * dédiée (ex: phpoffice/phpspreadsheet).
 */
class CsvExporter
{
    /**
     * @param  string[]  $entetes
     * @param  iterable<array<int, mixed>>  $lignes  Chaque ligne dans le même ordre que $entetes.
     */
    public static function streamer(string $nomFichier, array $entetes, iterable $lignes): StreamedResponse
    {
        return new StreamedResponse(function () use ($entetes, $lignes) {
            $flux = fopen('php://output', 'w');
            // BOM UTF-8 : Excel (Windows) n'affiche correctement les accents
            // français d'un CSV que si ce marqueur est présent en tête.
            fwrite($flux, "\xEF\xBB\xBF");
            fputcsv($flux, $entetes, ';');

            foreach ($lignes as $ligne) {
                fputcsv($flux, $ligne, ';');
            }

            fclose($flux);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$nomFichier}\"",
        ]);
    }
}
