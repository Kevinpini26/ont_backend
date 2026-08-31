<?php

namespace Modules\Kernel\Support;

use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Extraction du texte d'un document numérisé, pour le rendre cherchable —
 * voir docs/numerisation-courrier.md (Lot 4) et la migration
 * add_contenu_texte_to_documents_numerises.
 *
 * Deux étages, jamais bloquants l'un envers l'autre :
 * 1. Lecture directe du texte déjà intégré au PDF (smalot/pdfparser,
 *    pur PHP, toujours disponible) — couvre un fichier déjà numérique
 *    (téléversement direct), mais rend un texte vide pour un PDF composé
 *    uniquement d'images scannées (capture mobile, import USB).
 * 2. Reconnaissance de caractères (OCR), UNIQUEMENT si
 *    `config('kernel.ocr.active')` est vrai (variable d'environnement
 *    OCR_ACTIVE, désactivée par défaut) : delegue à deux binaires
 *    système — `pdftoppm` (paquet poppler-utils, rastérise chaque page
 *    en image) puis `tesseract` (paquet tesseract-ocr +
 *    tesseract-ocr-fra pour le dictionnaire français). Ce sont des
 *    binaires système, pas des extensions PHP : ils ne réintroduisent
 *    pas gd/imagick, volontairement absents de ce serveur (voir
 *    Dockerfile), mais ILS NE SONT PAS installés dans l'image actuelle —
 *    activer OCR_ACTIVE sans les avoir ajoutés à l'image ne fait
 *    qu'échouer proprement (avertissement journalisé, texte non
 *    disponible), jamais planter la file de traitement.
 */
class ExtracteurTexteDocumentNumerise
{
    private const LONGUEUR_MINIMALE_TEXTE_EMBARQUE = 20;

    public function extraire(string $contenuPdf): ?string
    {
        $texteEmbarque = $this->extraireTexteEmbarque($contenuPdf);

        if ($texteEmbarque !== null && mb_strlen(trim($texteEmbarque)) >= self::LONGUEUR_MINIMALE_TEXTE_EMBARQUE) {
            return trim($texteEmbarque);
        }

        if (! config('kernel.ocr.active')) {
            return $texteEmbarque !== null ? trim($texteEmbarque) : null;
        }

        return $this->extraireParOcr($contenuPdf) ?? $texteEmbarque;
    }

    private function extraireTexteEmbarque(string $contenuPdf): ?string
    {
        try {
            $document = (new Parser)->parseContent($contenuPdf);

            return $document->getText();
        } catch (\Throwable $e) {
            Log::warning('Extraction de texte PDF (smalot/pdfparser) échouée', ['erreur' => $e->getMessage()]);

            return null;
        }
    }

    private function extraireParOcr(string $contenuPdf): ?string
    {
        $dossierTemporaire = sys_get_temp_dir().'/ocr_'.bin2hex(random_bytes(8));
        mkdir($dossierTemporaire);
        $cheminPdf = "{$dossierTemporaire}/document.pdf";
        file_put_contents($cheminPdf, $contenuPdf);

        try {
            $rasterisation = new Process(['pdftoppm', '-png', '-r', '200', $cheminPdf, "{$dossierTemporaire}/page"]);
            $rasterisation->run();
            if (! $rasterisation->isSuccessful()) {
                throw new ProcessFailedException($rasterisation);
            }

            $pages = glob("{$dossierTemporaire}/page*.png");
            sort($pages);

            $texte = [];
            foreach ($pages as $pageImage) {
                $ocr = new Process(['tesseract', $pageImage, 'stdout', '-l', 'fra']);
                $ocr->run();
                if ($ocr->isSuccessful()) {
                    $texte[] = $ocr->getOutput();
                }
            }

            return $texte !== [] ? trim(implode("\n", $texte)) : null;
        } catch (\Throwable $e) {
            Log::warning("OCR indisponible (pdftoppm/tesseract absents ou en échec sur ce serveur) : {$e->getMessage()}");

            return null;
        } finally {
            array_map('unlink', glob("{$dossierTemporaire}/*") ?: []);
            @rmdir($dossierTemporaire);
        }
    }
}
