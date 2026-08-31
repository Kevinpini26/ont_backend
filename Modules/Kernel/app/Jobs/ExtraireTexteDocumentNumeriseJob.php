<?php

namespace Modules\Kernel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Modules\Kernel\Models\DocumentNumerise;
use Modules\Kernel\Support\ExtracteurTexteDocumentNumerise;

/**
 * L'extraction ne doit jamais se faire dans la requête HTTP (un OCR peut
 * prendre plusieurs secondes par page) — voir
 * docs/numerisation-courrier.md (Lot 4).
 */
class ExtraireTexteDocumentNumeriseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $documentNumeriseId) {}

    public function handle(ExtracteurTexteDocumentNumerise $extracteur): void
    {
        $document = DocumentNumerise::query()->find($this->documentNumeriseId);

        if ($document === null || ! Storage::disk('local')->exists($document->chemin)) {
            return;
        }

        $texte = $extracteur->extraire(Storage::disk('local')->get($document->chemin));

        if ($texte !== null && $texte !== '') {
            $document->update(['contenu_texte' => $texte]);
        }
    }
}
