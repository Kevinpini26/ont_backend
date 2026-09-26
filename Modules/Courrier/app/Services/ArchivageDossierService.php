<?php

namespace Modules\Courrier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Enums\MissionDocumentaireStatut;
use Modules\Courrier\Enums\TraitementDirectionStatut;
use Modules\Courrier\Models\Dossier;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;
use Modules\Kernel\Support\DgDisponibilite;

class ArchivageDossierService
{
    public function __construct(private readonly AuditLogger $audit, private readonly DelegationResolver $delegations) {}

    public function decider(Dossier $dossier, User $acteur): Dossier
    {
        return DB::transaction(function () use ($dossier, $acteur) {
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            if (! ($acteur->poste === Poste::DG || ($acteur->poste === Poste::DGA && ! DgDisponibilite::estDisponible()) || $this->delegations->posteDelegueAujourdhui($acteur) === Poste::DG)) {
                throw ValidationException::withMessages(['dossier' => 'Décision réservée à la DG/DGA habilitée.']);
            }
            if ($dossier->statut_archivage !== 'actif') {
                throw ValidationException::withMessages(['dossier' => "Le dossier n'est plus disponible pour une décision d'archivage."]);
            }
            $dossier->update(['statut_archivage' => 'a_archiver', 'archivage_decide_par_id' => $acteur->id, 'archivage_decide_at' => now()]);
            $this->audit->enregistrer('dossier.archivage_decide', $dossier, $acteur);

            return $dossier;
        });
    }

    public function archiver(Dossier $dossier, User $sec2): Dossier
    {
        return DB::transaction(function () use ($dossier, $sec2) {
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            if ($dossier->statut_archivage !== 'a_archiver' || ! $this->delegations->utilisateurHabilite($sec2, [Poste::SECRETARIAT_2])) {
                throw ValidationException::withMessages(['dossier' => 'Archivage non décidé ou acteur non habilité.']);
            } if ($dossier->documents()->whereDoesntHave('classement', fn ($q) => $q->where('statut', 'archive'))->exists() || $dossier->documents()->whereHas('dispatchs', fn ($q) => $q->where('statut', 'en_attente'))->exists() || $dossier->documents()->whereHas('missionsDocumentaires', fn ($q) => $q->whereIn('statut', [MissionDocumentaireStatut::ASSIGNEE, MissionDocumentaireStatut::EN_COURS]))->exists() || $dossier->documents()->whereHas('traitementsDirection', fn ($q) => $q->where('statut', '!=', TraitementDirectionStatut::TERMINE_DIRECTEUR))->exists() || $dossier->documents()->whereHas('documentProduitDirection', fn ($q) => $q->whereNotIn('statut', ['entre_circuit']))->exists()) {
                throw ValidationException::withMessages(['dossier' => 'Le dossier contient encore un document ou une opération active.']);
            } $dossier->update(['statut_archivage' => 'archive', 'archive_par_id' => $sec2->id, 'archive_at' => now()]);
            $this->audit->enregistrer('dossier.archive', $dossier, $sec2);

            return $dossier;
        });
    }
}
