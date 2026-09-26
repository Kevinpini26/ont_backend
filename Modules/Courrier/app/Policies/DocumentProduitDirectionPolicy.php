<?php

namespace Modules\Courrier\Policies;

use Modules\Courrier\Enums\DocumentProduitStatut;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;

class DocumentProduitDirectionPolicy
{
    public function view(User $user, DocumentProduitDirection $document): bool
    {
        if ($user->poste === Poste::RECEPTION && in_array($document->statut, [DocumentProduitStatut::TRANSMIS_RECEPTION, DocumentProduitStatut::ENTRE_CIRCUIT], true)) {
            return true;
        }

        return $user->direction_id === $document->direction_id && in_array($user->role, [UserRole::SECRETARIAT_DIRECTION, UserRole::DIRECTEUR_DIRECTION, UserRole::RESPONSABLE_DIRECTION], true);
    }

    public function soumettre(User $user, DocumentProduitDirection $document): bool
    {
        return $user->role === UserRole::SECRETARIAT_DIRECTION && $user->direction_id === $document->direction_id && in_array($document->statut, [DocumentProduitStatut::BROUILLON, DocumentProduitStatut::A_CORRIGER], true);
    }

    public function statuer(User $user, DocumentProduitDirection $document): bool
    {
        return $user->id === $document->traitement->directeur_id && $user->direction_id === $document->direction_id && $document->statut === DocumentProduitStatut::SOUMIS_DIRECTEUR;
    }

    public function transmettreReception(User $user, DocumentProduitDirection $document): bool
    {
        return $user->role === UserRole::SECRETARIAT_DIRECTION && $user->direction_id === $document->direction_id && $document->statut === DocumentProduitStatut::VALIDE;
    }

    public function recevoir(User $user, DocumentProduitDirection $document): bool
    {
        return $user->poste === Poste::RECEPTION && $document->statut === DocumentProduitStatut::TRANSMIS_RECEPTION;
    }
}
