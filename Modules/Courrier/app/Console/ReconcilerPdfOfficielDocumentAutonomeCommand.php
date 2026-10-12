<?php

namespace Modules\Courrier\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Courrier\Enums\DocumentProduitOrigine;
use Modules\Courrier\Enums\DocumentProduitStatut;
use Modules\Courrier\Models\DocumentProduitDirection;

class ReconcilerPdfOfficielDocumentAutonomeCommand extends Command
{
    protected $signature = 'courrier:reconcilier-pdf-officiels';

    protected $description = 'Signale les PDFs officiels autonomes potentiellement orphelins, absents ou altérés.';

    public function handle(): int
    {
        $disque = Storage::disk('local');
        $prefixe = 'documents-internes/officiels';
        $fichiers = $disque->allFiles($prefixe);
        $fichiersPotentiellementOrphelins = 0;
        $erreurs = 0;

        foreach (array_chunk($fichiers, 500) as $lot) {
            $references = DocumentProduitDirection::query()
                ->whereIn('pdf_officiel_chemin', $lot)
                ->pluck('pdf_officiel_chemin')
                ->all();
            $references = array_fill_keys($references, true);

            foreach ($lot as $chemin) {
                if (isset($references[$chemin])) {
                    continue;
                }

                $fichiersPotentiellementOrphelins++;
                $this->warn("Fichier potentiellement orphelin conservé : {$chemin}");
            }
        }

        DocumentProduitDirection::query()
            ->where('origine', DocumentProduitOrigine::AUTONOME)
            ->where('statut', DocumentProduitStatut::VALIDE)
            ->whereNotNull('pdf_officiel_chemin')
            ->select(['id', 'pdf_officiel_chemin', 'pdf_officiel_sha256'])
            ->chunkById(200, function ($documents) use ($disque, &$erreurs): void {
                foreach ($documents as $document) {
                    $chemin = $document->pdf_officiel_chemin;
                    if (! $disque->exists($chemin)
                        || ! is_string($document->pdf_officiel_sha256)
                        || ! is_string($contenu = $disque->get($chemin))
                        || ! hash_equals($document->pdf_officiel_sha256, hash('sha256', $contenu))) {
                        $erreurs++;
                        Log::critical('Un PDF officiel autonome référencé est absent ou altéré.', [
                            'document_id' => $document->id,
                        ]);
                        $this->error("PDF officiel référencé absent ou altéré pour le document {$document->id}.");
                    }
                }
            });

        $this->info("{$fichiersPotentiellementOrphelins} fichier(s) potentiellement orphelin(s) signalé(s), aucun fichier supprimé.");
        if ($erreurs > 0) {
            $this->error("{$erreurs} incohérence(s) de PDF officiel détectée(s); voir les journaux.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
