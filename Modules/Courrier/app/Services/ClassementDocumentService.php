<?php

namespace Modules\Courrier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Enums\ClassementDocumentStatut;
use Modules\Courrier\Enums\DispatchStatut;
use Modules\Courrier\Enums\DispatchTypeDestination;
use Modules\Courrier\Models\ClassementDocument;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;

class ClassementDocumentService
{
    public function __construct(private readonly DispatchCourrierService $dispatchs, private readonly AuditLogger $audit, private readonly DelegationResolver $delegations) {}

    public function classer(DispatchCourrier $dispatch, User $sec2, array $data): ClassementDocument
    {
        return DB::transaction(function () use ($dispatch, $sec2, $data) {
            $dispatch = $this->dispatchs->verrouillerPourExecution($dispatch, $sec2);
            if ($dispatch->type_destination !== DispatchTypeDestination::CLASSEMENT || $dispatch->statut !== DispatchStatut::EN_ATTENTE || ! $this->delegations->utilisateurHabilite($sec2, [Poste::SECRETARIAT_2])) {
                throw ValidationException::withMessages(['dispatch' => 'Seul SEC2 peut exécuter une décision de classement en attente.']);
            }
            if ($dispatch->courrier->mode_sortie !== null && ! $dispatch->courrier->estSortieCompletee()) {
                throw ValidationException::withMessages(['courrier' => 'Le classement institutionnel exige une sortie explicitement terminée.']);
            }
            if (ClassementDocument::query()->where('courrier_id', $dispatch->courrier_id)->exists()) {
                throw ValidationException::withMessages(['courrier' => 'Ce document possède déjà un classement institutionnel.']);
            } $classement = ClassementDocument::query()->create(['courrier_id' => $dispatch->courrier_id, 'dossier_id' => $dispatch->dossier_id, 'dispatch_courrier_id' => $dispatch->id, 'statut' => ClassementDocumentStatut::CLASSE, 'cote' => $data['cote'] ?? null, 'emplacement' => $data['emplacement'], 'observation' => $data['observation'] ?? null, 'classe_par_id' => $sec2->id, 'classe_at' => now()]);
            Courrier::withoutGlobalScopes()->whereKey($dispatch->courrier_id)->update(['cote_classement' => $classement->cote, 'emplacement_physique' => $classement->emplacement]);
            $this->dispatchs->executerClassement($dispatch, $sec2, $classement);
            $this->audit->enregistrer('classement_document.classe', $classement, $sec2, $data);

            return $classement->load(['courrier', 'dispatch.decisionnaire', 'classePar']);
        });
    }

    public function archiver(ClassementDocument $classement, User $sec2, ?string $observation): ClassementDocument
    {
        return DB::transaction(function () use ($classement, $sec2, $observation) {
            $classement = ClassementDocument::query()->lockForUpdate()->findOrFail($classement->id);
            if ($classement->statut !== ClassementDocumentStatut::CLASSE || ! $this->delegations->utilisateurHabilite($sec2, [Poste::SECRETARIAT_2])) {
                throw ValidationException::withMessages(['classement' => 'Seul SEC2 peut archiver un document classé.']);
            } $classement->update(['statut' => ClassementDocumentStatut::ARCHIVE, 'archive_par_id' => $sec2->id, 'archive_at' => now(), 'observation' => $observation ?? $classement->observation]);
            $this->audit->enregistrer('classement_document.archive', $classement, $sec2, ['observation' => $observation]);

            return $classement->load(['courrier', 'dispatch.decisionnaire', 'classePar', 'archivePar']);
        });
    }

    public function corriger(ClassementDocument $classement, User $sec2, array $data): ClassementDocument
    {
        return DB::transaction(function () use ($classement, $sec2, $data) {
            $classement = ClassementDocument::query()->lockForUpdate()->findOrFail($classement->id);
            if ($classement->statut === ClassementDocumentStatut::ARCHIVE) {
                throw ValidationException::withMessages(['classement' => 'Un document archivé ne permet plus la correction ordinaire de ses métadonnées.']);
            }
            if (! $this->delegations->utilisateurHabilite($sec2, [Poste::SECRETARIAT_2])) {
                throw ValidationException::withMessages(['classement' => 'Seul SEC2 peut corriger ces métadonnées.']);
            } $avant = $classement->only(['cote', 'emplacement', 'observation']);
            $classement->update(['cote' => $data['cote'] ?? $classement->cote, 'emplacement' => $data['emplacement'] ?? $classement->emplacement, 'observation' => $data['observation'] ?? $classement->observation]);
            Courrier::withoutGlobalScopes()->whereKey($classement->courrier_id)->update(['cote_classement' => $classement->cote, 'emplacement_physique' => $classement->emplacement]);
            $this->audit->enregistrer('classement_document.metadonnees_corrigees', $classement, $sec2, ['avant' => $avant, 'apres' => $classement->only(['cote', 'emplacement', 'observation']), 'motif' => $data['motif']]);

            return $classement;
        });
    }
}
