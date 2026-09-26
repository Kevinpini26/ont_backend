<?php

namespace Modules\Courrier\Services;

use Illuminate\Validation\ValidationException;
use Modules\Courrier\Enums\DispatchStatut;
use Modules\Courrier\Enums\DispatchTypeDestination;
use Modules\Courrier\Enums\MissionDocumentaireStatut;
use Modules\Courrier\Enums\TraitementDirectionStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DispatchCourrier;

class CycleDecisionnelService
{
    public function __construct(private readonly DossierWorkflowGuard $dossiers) {}

    public function assertPeutOuvrir(Courrier $courrier): void
    {
        $this->dossiers->assertCourrierActifPourNouvelleActivite($courrier);

        if ($courrier->classement()->exists()) {
            throw ValidationException::withMessages(['courrier' => 'Un document déjà classé ne peut pas ouvrir un nouveau cycle décisionnel.']);
        }
        if ($courrier->missionsDocumentaires()->whereIn('statut', [MissionDocumentaireStatut::ASSIGNEE, MissionDocumentaireStatut::EN_COURS])->exists()) {
            throw ValidationException::withMessages(['courrier' => 'Une mission documentaire est encore active.']);
        }
        if ($courrier->dispatchs()->where('statut', DispatchStatut::EN_ATTENTE)->exists()) {
            throw ValidationException::withMessages(['courrier' => 'Le cycle décisionnel précédent contient encore un dispatch en attente.']);
        }
        if ($courrier->dispatchs()
            ->where('type_destination', DispatchTypeDestination::DIRECTION)
            ->where(function ($query) {
                $query->whereDoesntHave('traitementDirection')
                    ->orWhereHas('traitementDirection', fn ($traitement) => $traitement->where('statut', '!=', TraitementDirectionStatut::TERMINE_DIRECTEUR));
            })->exists()) {
            throw ValidationException::withMessages(['courrier' => 'Un traitement directionnel du cycle précédent est encore actif.']);
        }
    }

    public function peutOuvrir(Courrier $courrier): bool
    {
        try {
            $this->assertPeutOuvrir($courrier);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    public function prochainNumero(Courrier $courrier): int
    {
        return ((int) DispatchCourrier::query()->where('courrier_id', $courrier->id)->max('cycle')) + 1;
    }
}
