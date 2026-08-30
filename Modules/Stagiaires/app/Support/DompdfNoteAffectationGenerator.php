<?php

namespace Modules\Stagiaires\Support;

use Illuminate\Support\Facades\Storage;
use Modules\Kernel\Contracts\PdfGenerationService;
use Modules\Stagiaires\Contracts\NoteAffectationGenerator;
use Modules\Stagiaires\Models\Stagiaire;

class DompdfNoteAffectationGenerator implements NoteAffectationGenerator
{
    public function __construct(private readonly PdfGenerationService $pdf) {}

    public function generer(Stagiaire $stagiaire): string
    {
        $contenu = $this->pdf->genererDepuisVue('stagiaires::note-affectation', ['stagiaire' => $stagiaire]);

        $chemin = "notes-affectation/note-affectation-stagiaire-{$stagiaire->id}.pdf";

        Storage::disk('local')->put($chemin, $contenu);

        return $chemin;
    }
}
