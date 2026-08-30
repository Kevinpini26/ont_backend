<?php

namespace Modules\Stagiaires\Support;

use Illuminate\Support\Facades\Storage;
use Modules\Kernel\Contracts\PdfGenerationService;
use Modules\Stagiaires\Contracts\CertificatGenerator;
use Modules\Stagiaires\Models\Stagiaire;

class DompdfCertificatGenerator implements CertificatGenerator
{
    public function __construct(private readonly PdfGenerationService $pdf) {}

    public function generer(Stagiaire $stagiaire): string
    {
        $contenu = $this->pdf->genererDepuisVue('stagiaires::certificat', ['stagiaire' => $stagiaire]);

        $chemin = "certificats/certificat-fin-stage-{$stagiaire->id}.pdf";

        Storage::disk('local')->put($chemin, $contenu);

        return $chemin;
    }
}
