<?php

namespace Modules\Courrier\Policies;

use Modules\Courrier\Enums\AvisDg;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DispatchStatut;
use Modules\Courrier\Enums\DispatchTypeDestination;
use Modules\Courrier\Enums\DocumentProduitOrigine;
use Modules\Courrier\Enums\DocumentProduitStatut;
use Modules\Courrier\Enums\ModeSortie;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;

class DispatchCourrierPolicy
{
    public function __construct(private readonly DelegationResolver $delegations) {}

    public function view(User $user, DispatchCourrier $dispatch): bool
    {
        if ($this->delegations->utilisateurHabilite($user, [Poste::DG, Poste::DGA, Poste::SECRETARIAT_2])) {
            return true;
        }

        return $dispatch->type_destination === DispatchTypeDestination::DIRECTION
            && $dispatch->statut === DispatchStatut::EXECUTE
            && $user->role === UserRole::SECRETARIAT_DIRECTION
            && $user->direction_id === $dispatch->direction_id;
    }

    public function executer(User $user, DispatchCourrier $dispatch): bool
    {
        return $dispatch->statut === DispatchStatut::EN_ATTENTE
            && $this->delegations->utilisateurHabilite($user, [Poste::SECRETARIAT_2]);
    }

    public function consulterScanAutonomeSec2(User $user, DispatchCourrier $dispatch): bool
    {
        $courrier = $dispatch->courrier;
        $document = $courrier?->documentProduitDirection;
        $estDecisionValide = in_array($dispatch->decisionnaire_poste, [Poste::DG, Poste::DGA], true)
            && $dispatch->autorite_poste === Poste::DG
            && $dispatch->decide_at !== null
            && $courrier->avis_dg_rendu_par_id === $dispatch->decisionnaire_id
            && $courrier->avis_dg_rendu_at !== null
            && $courrier->avis_dg === AvisDg::FAVORABLE
            && $courrier->direction_origine_id === $document?->direction_id;

        if ($dispatch->type_destination !== DispatchTypeDestination::DIRECTION
            || $dispatch->dossier_id !== null
            || $courrier->dossier_id !== null
            || $courrier->direction_destination_id !== $dispatch->direction_id
            || ! $estDecisionValide
            || $document?->origine !== DocumentProduitOrigine::AUTONOME
            || $document->statut !== DocumentProduitStatut::ENTRE_CIRCUIT
            || $document->traitement_direction_id !== null
            || $document->document_source_id !== null
            || $document->scan_officiel_document_id === null
            || $document->transmis_reception_par_id === null
            || $document->transmis_reception_at === null
            || $document->recu_par_id === null
            || $document->recu_at === null
            || ! is_string($courrier->reference_documentaire)
            || preg_match('/\A\d{3,}\/[A-Z]{1,12}\/[A-Z0-9]{1,20}\/ONT\/\d{4}\z/', $courrier->reference_documentaire) !== 1
            || blank($courrier->numero_enregistrement)) {
            return false;
        }

        $estDispatchOuvert = $dispatch->statut === DispatchStatut::EN_ATTENTE
            && $courrier->statut === CourrierStatut::EN_DISPATCH;
        $estDispatchTransmis = $dispatch->statut === DispatchStatut::EXECUTE
            && $dispatch->execute_par_id !== null
            && $dispatch->execute_at !== null
            && $courrier->statut === CourrierStatut::DISPATCH_EXECUTE;
        if (! $estDispatchOuvert && ! $estDispatchTransmis) {
            return false;
        }

        if ($this->delegations->utilisateurHabilite($user, [Poste::SECRETARIAT_2])) {
            return true;
        }

        return $estDispatchTransmis
            && $user->role === UserRole::SECRETARIAT_DIRECTION
            && $user->direction_id === $dispatch->direction_id;
    }

    public function accuserReception(User $user, DispatchCourrier $dispatch): bool
    {
        if ($dispatch->type_destination === DispatchTypeDestination::CLASSEMENT) {
            $courrier = $dispatch->courrier;

            return $dispatch->statut === DispatchStatut::EN_ATTENTE
                && $dispatch->accuse_reception_at === null
                && $courrier !== null
                && in_array($courrier->mode_sortie, [ModeSortie::COURRIEL, ModeSortie::RETRAIT_PHYSIQUE, ModeSortie::COURRIEL_ET_RETRAIT], true)
                && $courrier->estSortieCompletee()
                && $courrier->estCycleClassementApresSortieCompletee()
                && $dispatch->cycle === (int) $courrier->dispatchs()->max('cycle')
                && $this->delegations->utilisateurHabilite($user, [Poste::SECRETARIAT_2]);
        }

        return $dispatch->type_destination === DispatchTypeDestination::DIRECTION
            && $dispatch->statut === DispatchStatut::EXECUTE
            && $dispatch->accuse_reception_at === null
            && $user->role === UserRole::SECRETARIAT_DIRECTION
            && $user->direction_id === $dispatch->direction_id;
    }
}
