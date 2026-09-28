<?php

namespace Modules\Courrier\Services;

use Illuminate\Validation\ValidationException;
use Modules\Courrier\Enums\DispatchStatut;
use Modules\Courrier\Enums\DispatchTypeDestination;
use Modules\Courrier\Enums\MissionDocumentaireStatut;
use Modules\Courrier\Enums\TraitementDirectionStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Kernel\Enums\Poste;

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
        if (DocumentProduitDirection::query()->where('document_source_id', $courrier->id)
            ->where('statut', '!=', 'entre_circuit')->exists()) {
            throw ValidationException::withMessages(['courrier' => 'Un document produit par une direction est encore actif.']);
        }
    }

    public function assertReceptionDg(Courrier $courrier): void
    {
        $bordereau = $courrier->bordereauCourant();
        if ($bordereau !== null && $bordereau->destinataire_poste === Poste::DG->value
            && $bordereau->accuse_reception_at === null) {
            throw ValidationException::withMessages(['courrier' => 'Le bordereau destiné à la DG doit être réceptionné avant décision.']);
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
