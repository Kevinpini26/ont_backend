<?php

namespace Modules\Courrier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Enums\DocumentRelationType;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\Dossier;
use Modules\Courrier\Models\DocumentRelation;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Models\User;

class DocumentRelationService
{
    public function __construct(private readonly AuditLogger $audit, private readonly DossierWorkflowGuard $dossiers) {}

    public function relier(Courrier $source, Courrier $cible, DocumentRelationType $type, ?User $acteur): DocumentRelation
    {
        if ($source->dossier_id === null || $source->dossier_id !== $cible->dossier_id) {
            throw ValidationException::withMessages(['document_cible_id' => 'Les documents reliés doivent appartenir au même dossier.']);
        }
        if ($source->is($cible)) {
            throw ValidationException::withMessages(['document_cible_id' => 'Un document ne peut pas être relié à lui-même.']);
        }

        return DB::transaction(function () use ($source, $cible, $type, $acteur) {
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($source->dossier_id);
            $this->dossiers->assertActifPourNouvelleActivite($dossier);
            $this->dossiers->assertCourrierActifPourNouvelleActivite($source);
            $this->dossiers->assertCourrierActifPourNouvelleActivite($cible);

            if ($this->creeraitCycle($source->id, $cible->id)) {
                throw ValidationException::withMessages(['document_cible_id' => 'Cette relation créerait un cycle documentaire.']);
            }

            $relation = DocumentRelation::query()->firstOrCreate([
                'document_source_id' => $source->id,
                'document_cible_id' => $cible->id,
                'type_relation' => $type,
            ], ['created_by' => $acteur?->id]);

            if ($relation->wasRecentlyCreated) {
                $this->audit->enregistrer('document.relation_creee', $relation, $acteur, [
                    'document_source_id' => $source->id,
                    'document_cible_id' => $cible->id,
                    'type_relation' => $type->value,
                ]);
            }

            return $relation;
        });
    }

    private function creeraitCycle(int $sourceId, int $cibleId): bool
    {
        $aVisiter = [$cibleId];
        $visites = [];

        while ($aVisiter !== []) {
            $courant = array_pop($aVisiter);
            if ($courant === $sourceId) {
                return true;
            }
            if (isset($visites[$courant])) {
                continue;
            }
            $visites[$courant] = true;
            foreach (DocumentRelation::query()->where('document_source_id', $courant)->pluck('document_cible_id') as $suivant) {
                $aVisiter[] = $suivant;
            }
        }

        return false;
    }
}
