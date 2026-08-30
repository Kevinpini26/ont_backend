<?php

namespace Modules\Stagiaires\Support;

use Illuminate\Support\Facades\Storage;
use Modules\Kernel\Contracts\PdfGenerationService;
use Modules\Stagiaires\Contracts\BadgeStagiairePdfGenerator;
use Modules\Stagiaires\Enums\DocumentType;
use Modules\Stagiaires\Models\Stagiaire;

class DompdfBadgeStagiairePdfGenerator implements BadgeStagiairePdfGenerator
{
    public function __construct(private readonly PdfGenerationService $pdf) {}

    public function generer(Stagiaire $stagiaire): string
    {
        return $this->pdf->genererDepuisVue('stagiaires::badge', [
            'stagiaire' => $stagiaire,
            'photoDataUri' => $this->genererPhotoDataUri($stagiaire),
        ]);
    }

    private function genererPhotoDataUri(Stagiaire $stagiaire): ?string
    {
        $photo = $stagiaire->documents->firstWhere('type', DocumentType::PHOTO);

        if ($photo === null || ! Storage::disk('local')->exists($photo->chemin)) {
            return null;
        }

        $mime = Storage::disk('local')->mimeType($photo->chemin) ?: 'image/jpeg';
        $contenu = Storage::disk('local')->get($photo->chemin);

        return "data:{$mime};base64,".base64_encode($contenu);
    }
}
