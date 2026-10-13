<?php

namespace Modules\Courrier\Policies;

use Modules\Courrier\Enums\DocumentProduitOrigine;
use Modules\Courrier\Enums\DocumentProduitStatut;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;

class DocumentProduitDirectionPolicy
{
    public function view(User $user, DocumentProduitDirection $document): bool
    {
        if ($document->origine === DocumentProduitOrigine::AUTONOME) {
            return $document->estVisiblePour($user);
        }

        if ($user->poste === Poste::RECEPTION && in_array($document->statut, [DocumentProduitStatut::TRANSMIS_RECEPTION, DocumentProduitStatut::ENTRE_CIRCUIT], true)) {
            return true;
        }

        return $user->direction_id === $document->direction_id && in_array($user->role, [UserRole::SECRETARIAT_DIRECTION, UserRole::DIRECTEUR_DIRECTION, UserRole::RESPONSABLE_DIRECTION], true);
    }

    public function downloadPdfOfficiel(User $user, DocumentProduitDirection $document): bool
    {
        return $document->origine === DocumentProduitOrigine::AUTONOME
            && in_array($document->statut, [DocumentProduitStatut::VALIDE, DocumentProduitStatut::SCAN_ENREGISTRE], true)
            && $document->estVisiblePour($user);
    }

    public function deposerScanOfficiel(User $user, DocumentProduitDirection $document): bool
    {
        return $document->origine === DocumentProduitOrigine::AUTONOME
            && in_array($document->statut, [DocumentProduitStatut::VALIDE, DocumentProduitStatut::SCAN_ENREGISTRE], true)
            && $user->role === UserRole::SECRETARIAT_DIRECTION
            && $user->direction_id === $document->direction_id;
    }

    public function consulterScanOfficiel(User $user, DocumentProduitDirection $document): bool
    {
        if ($user->role === UserRole::AGENT_CIRCUIT_COURRIER
            && $user->poste === Poste::RECEPTION) {
            return $document->origine === DocumentProduitOrigine::AUTONOME
                && $document->statut === DocumentProduitStatut::TRANSMIS_RECEPTION
                && $document->scan_officiel_document_id !== null
                && $document->transmis_reception_par_id !== null
                && $document->transmis_reception_at !== null
                && $document->recu_par_id === null
                && $document->recu_at === null;
        }

        if ($document->origine !== DocumentProduitOrigine::AUTONOME
            || ! in_array($document->statut, [
                DocumentProduitStatut::SCAN_ENREGISTRE,
                DocumentProduitStatut::TRANSMIS_RECEPTION,
            ], true)
            || $document->scan_officiel_document_id === null
            || (($document->transmis_reception_par_id === null) !== ($document->transmis_reception_at === null))
            || $user->direction_id !== $document->direction_id) {
            return false;
        }

        if ($user->role === UserRole::SECRETARIAT_DIRECTION) {
            return true;
        }

        return in_array($user->role, [UserRole::DIRECTEUR_DIRECTION, UserRole::RESPONSABLE_DIRECTION], true)
            && $user->id === $document->directeur_id
            && $document->soumis_at !== null
            && $document->soumis_par_id !== null;
    }

    public function soumettre(User $user, DocumentProduitDirection $document): bool
    {
        $statuts = $document->origine === DocumentProduitOrigine::AUTONOME
            ? [DocumentProduitStatut::BROUILLON]
            : [DocumentProduitStatut::BROUILLON, DocumentProduitStatut::A_CORRIGER];

        return $user->role === UserRole::SECRETARIAT_DIRECTION
            && $user->direction_id === $document->direction_id
            && in_array($document->statut, $statuts, true);
    }

    public function createAutonome(User $user): bool
    {
        return $user->role === UserRole::SECRETARIAT_DIRECTION
            && $user->direction_id !== null;
    }

    public function modifierBrouillon(User $user, DocumentProduitDirection $document): bool
    {
        return $document->origine === DocumentProduitOrigine::AUTONOME
            && $user->role === UserRole::SECRETARIAT_DIRECTION
            && $user->direction_id === $document->direction_id
            && in_array($document->statut, [DocumentProduitStatut::BROUILLON, DocumentProduitStatut::A_CORRIGER], true);
    }

    public function statuer(User $user, DocumentProduitDirection $document): bool
    {
        $directeurId = $document->origine === DocumentProduitOrigine::AUTONOME
            ? $document->directeur_id
            : $document->traitement?->directeur_id;

        return $directeurId !== null
            && $user->id === $directeurId
            && $user->direction_id === $document->direction_id
            && $user->role->estDirecteurDirection()
            && $document->statut === DocumentProduitStatut::SOUMIS_DIRECTEUR;
    }

    public function transmettreReception(User $user, DocumentProduitDirection $document): bool
    {
        if ($document->origine === DocumentProduitOrigine::AUTONOME) {
            return $user->role === UserRole::SECRETARIAT_DIRECTION
                && $user->direction_id === $document->direction_id
                && $document->statut === DocumentProduitStatut::SCAN_ENREGISTRE
                && $document->scan_officiel_document_id !== null
                && (($document->transmis_reception_par_id === null && $document->transmis_reception_at === null)
                    || ($document->transmis_reception_par_id !== null
                        && $document->transmis_reception_at !== null
                        && $document->correction_demandee_at !== null))
                && $document->recu_par_id === null
                && $document->recu_at === null;
        }

        return $user->role === UserRole::SECRETARIAT_DIRECTION
            && $user->direction_id === $document->direction_id
            && $document->statut === DocumentProduitStatut::VALIDE;
    }

    public function recevoir(User $user, DocumentProduitDirection $document): bool
    {
        return $document->origine !== DocumentProduitOrigine::AUTONOME
            && $user->poste === Poste::RECEPTION
            && $document->statut === DocumentProduitStatut::TRANSMIS_RECEPTION;
    }

    public function accepterAutonomeReception(User $user, DocumentProduitDirection $document): bool
    {
        return $this->controlerAutonomeReception($user, $document);
    }

    public function demanderCorrectionAutonomeReception(User $user, DocumentProduitDirection $document): bool
    {
        return $this->controlerAutonomeReception($user, $document);
    }

    public function consulterControleAutonomeReception(User $user, DocumentProduitDirection $document): bool
    {
        return $user->role === UserRole::AGENT_CIRCUIT_COURRIER
            && $user->poste === Poste::RECEPTION
            && $document->origine === DocumentProduitOrigine::AUTONOME
            && in_array($document->statut, [
                DocumentProduitStatut::TRANSMIS_RECEPTION,
                DocumentProduitStatut::A_CORRIGER,
                DocumentProduitStatut::ENTRE_CIRCUIT,
            ], true)
            && $document->transmis_reception_par_id !== null
            && $document->transmis_reception_at !== null;
    }

    private function controlerAutonomeReception(User $user, DocumentProduitDirection $document): bool
    {
        return $user->role === UserRole::AGENT_CIRCUIT_COURRIER
            && $user->poste === Poste::RECEPTION
            && $document->origine === DocumentProduitOrigine::AUTONOME
            && $document->statut === DocumentProduitStatut::TRANSMIS_RECEPTION
            && $document->transmis_reception_par_id !== null
            && $document->transmis_reception_at !== null
            && $document->scan_officiel_document_id !== null
            && $document->recu_par_id === null
            && $document->recu_at === null;
    }
}
