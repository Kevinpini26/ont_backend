<?php

namespace Modules\Courrier\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Models\Courrier;

class PdfOfficielIntegrity
{
    public function verifier(Courrier $courrier): void
    {
        $chemin = $courrier->pdf_chemin;
        if (blank($chemin) || str_starts_with($chemin, '/') || str_contains($chemin, '..')
            || str_contains($chemin, '://') || str_contains($chemin, '\\')
            || blank($courrier->pdf_sha256) || ! Storage::disk('local')->exists($chemin)) {
            throw ValidationException::withMessages(['pdf' => 'Le PDF officiel ou son empreinte est absent ou invalide.']);
        }
        if (! hash_equals($courrier->pdf_sha256, hash('sha256', Storage::disk('local')->get($chemin)))) {
            throw ValidationException::withMessages(['pdf' => 'Le PDF officiel a été altéré et ne peut pas être communiqué.']);
        }
    }
}
