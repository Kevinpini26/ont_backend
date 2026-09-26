<?php

namespace Modules\Courrier\Services;

use Illuminate\Validation\ValidationException;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\Dossier;

class DossierWorkflowGuard
{
    public function assertActifPourNouvelleActivite(Dossier|int $dossier): void
    {
        $dossier = $dossier instanceof Dossier
            ? $dossier->fresh()
            : Dossier::query()->findOrFail($dossier);

        if ($dossier->statut_archivage === 'archive') {
            throw ValidationException::withMessages([
                'dossier' => 'Un dossier archivé ne peut plus recevoir de nouvelle activité métier.',
            ]);
        }
    }

    public function assertCourrierActifPourNouvelleActivite(Courrier $courrier): void
    {
        if ($courrier->estArchive()) {
            throw ValidationException::withMessages([
                'courrier' => 'Un document archivé ne peut plus recevoir de nouvelle activité métier.',
            ]);
        }

        if ($courrier->dossier_id !== null) {
            $this->assertActifPourNouvelleActivite($courrier->dossier_id);
        }
    }
}
