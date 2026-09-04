<?php

namespace Modules\Stagiaires\Support;

use Illuminate\Support\Facades\Storage;
use Modules\Kernel\Contracts\PdfGenerationService;
use Modules\Stagiaires\Contracts\TableauRepartitionPdfGenerator;
use Modules\Stagiaires\Models\TableauRepartition;

class DompdfTableauRepartitionPdfGenerator implements TableauRepartitionPdfGenerator
{
    public function __construct(private readonly PdfGenerationService $pdf) {}

    public function generer(TableauRepartition $tableau): string
    {
        $contenu = $this->pdf->genererDepuisVue('stagiaires::tableau-repartition', ['tableau' => $tableau]);

        $chemin = "tableaux-repartition/tableau-repartition-{$tableau->id}.pdf";

        Storage::disk('local')->put($chemin, $contenu);

        return $chemin;
    }
}
