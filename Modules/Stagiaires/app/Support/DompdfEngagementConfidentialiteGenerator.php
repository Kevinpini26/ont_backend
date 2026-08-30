<?php

namespace Modules\Stagiaires\Support;

use Illuminate\Support\Facades\Storage;
use Modules\Kernel\Contracts\PdfGenerationService;
use Modules\Stagiaires\Contracts\EngagementConfidentialiteGenerator;
use Modules\Stagiaires\Models\Stagiaire;

class DompdfEngagementConfidentialiteGenerator implements EngagementConfidentialiteGenerator
{
    public function __construct(private readonly PdfGenerationService $pdf) {}

    public function generer(Stagiaire $stagiaire): string
    {
        $contenu = $this->pdf->genererDepuisVue('stagiaires::engagement-confidentialite', ['stagiaire' => $stagiaire]);

        $chemin = "engagements-confidentialite/engagement-stagiaire-{$stagiaire->id}.pdf";

        Storage::disk('local')->put($chemin, $contenu);

        return $chemin;
    }
}
