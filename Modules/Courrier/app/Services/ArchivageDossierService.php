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
use Modules\Kernel\Support\DgAuthorityResolver;

class ArchivageDossierService
{
    public function __construct(private readonly AuditLogger $audit, private readonly DelegationResolver $delegations, private readonly DgAuthorityResolver $autoriteDg) {}

    public function decider(Dossier $dossier, User $acteur): Dossier
    {
        return DB::transaction(function () use ($dossier, $acteur) {
            $source = $this->autoriteDg->source($acteur);
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            if ($source === null) {
                throw ValidationException::withMessages(['dossier' => 'Décision réservée à la DG/DGA habilitée.']);
            }
            if ($dossier->statut_archivage !== 'actif') {
                throw ValidationException::withMessages(['dossier' => "Le dossier n'est plus disponible pour une décision d'archivage."]);
            }
            $this->assertEligiblePourArchivage($dossier);
            $dossier->update(['statut_archivage' => 'a_archiver', 'archivage_decide_par_id' => $acteur->id, 'archivage_decide_at' => now()]);
            $this->audit->enregistrer('dossier.archivage_decide', $dossier, $acteur, ['source_autorite' => $source]);

            return $dossier;
        });
    }

    public function archiver(Dossier $dossier, User $sec2): Dossier
    {
        return DB::transaction(function () use ($dossier, $sec2) {
            $dossier = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            if ($dossier->statut_archivage !== 'a_archiver' || ! $this->delegations->utilisateurHabilite($sec2, [Poste::SECRETARIAT_2])) {
                throw ValidationException::withMessages(['dossier' => 'Archivage non décidé ou acteur non habilité.']);
            }
            $this->assertEligiblePourArchivage($dossier);
            $dossier->update(['statut_archivage' => 'archive', 'archive_par_id' => $sec2->id, 'archive_at' => now()]);
            $this->audit->enregistrer('dossier.archive', $dossier, $sec2);

            return $dossier;
        });
    }

    private function assertEligiblePourArchivage(Dossier $dossier): void
    {
        $documentNonArchive = $dossier->documents()
            ->whereDoesntHave('classement', fn ($q) => $q->where('statut', 'archive'))
            ->exists();
        $dispatchEnAttente = $dossier->documents()
            ->whereHas('dispatchs', fn ($q) => $q->where('statut', 'en_attente'))
            ->exists();
        $missionActive = $dossier->documents()
            ->whereHas('missionsDocumentaires', fn ($q) => $q->whereIn('statut', [MissionDocumentaireStatut::ASSIGNEE, MissionDocumentaireStatut::EN_COURS]))
            ->exists();
        $traitementActif = $dossier->documents()
            ->whereHas('traitementsDirection', fn ($q) => $q->where('statut', '!=', TraitementDirectionStatut::TERMINE_DIRECTEUR))
            ->exists();
        $documentProduitActif = $dossier->documents()
            ->whereHas('documentProduitDirection', fn ($q) => $q->whereNotIn('statut', ['entre_circuit']))
            ->exists();

        if ($documentNonArchive || $dispatchEnAttente || $missionActive || $traitementActif || $documentProduitActif) {
            throw ValidationException::withMessages([
                'dossier' => 'Le dossier ne peut pas encore être proposé à l’archivage : il contient un document non archivé ou une opération active.',
            ]);
        }
    }
}
