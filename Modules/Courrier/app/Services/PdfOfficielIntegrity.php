<?php

namespace Modules\Courrier\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Models\Courrier;

class PdfOfficielIntegrity
{
    public function verifier(Courrier $courrier): void
    {
        $this->verifierFichier($courrier->pdf_chemin, $courrier->pdf_sha256);
    }

    public function verifierFichier(?string $chemin, ?string $sha256, string $cle = 'pdf'): void
    {
        if (blank($chemin) || str_starts_with($chemin, '/') || str_contains($chemin, '..')
            || str_contains($chemin, '://') || str_contains($chemin, '\\')
            || blank($sha256) || ! Storage::disk('local')->exists($chemin)) {
            throw ValidationException::withMessages([$cle => 'Le PDF ou son empreinte est absent ou invalide.']);
        }
        if (! hash_equals($sha256, hash('sha256', Storage::disk('local')->get($chemin)))) {
            throw ValidationException::withMessages([$cle => 'Le PDF a été altéré et ne peut pas être communiqué.']);
        }
    }
}
