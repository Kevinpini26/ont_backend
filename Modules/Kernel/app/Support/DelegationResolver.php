<?php

namespace Modules\Kernel\Support;

use Illuminate\Support\Facades\Date;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\DelegationPoste;
use Modules\Kernel\Models\User;

/**
 * Généralise à tout poste du circuit courrier le principe déjà en place
 * pour la seule DG (voir DgDisponibilite, non modifiée ici — son mécanisme
 * spécifique à l'intérim DG/DGA reste en l'état) : un utilisateur peut agir
 * au nom d'un poste qui n'est pas le sien s'il en détient une délégation
 * active aujourd'hui.
 */
class DelegationResolver
{
    /**
     * @param  Poste[]  $postesAutorises
     */
    public function utilisateurHabilite(User $utilisateur, array $postesAutorises): bool
    {
        if ($utilisateur->poste !== null && in_array($utilisateur->poste, $postesAutorises, true)) {
            return true;
        }

        $posteDelegue = $this->posteDelegueAujourdhui($utilisateur);

        return $posteDelegue !== null && in_array($posteDelegue, $postesAutorises, true);
    }

    /**
     * Le poste que cet utilisateur détient par délégation active
     * aujourd'hui, s'il en a une — sert aussi à marquer une action comme
     * "en intérim" (voir CourrierTransition::agi_en_interim) et à décider
     * de la visibilité inter-directions (voir
     * DefaultDirectionScopeBypassResolver). Une seule délégation active à
     * la fois par utilisateur est supposée ; en cas de chevauchement
     * (erreur de saisie), la plus récemment créée gagne.
     */
    public function posteDelegueAujourdhui(User $utilisateur): ?Poste
    {
        // ->value('poste') hydrate quand même le modèle pour appliquer le
        // cast Poste::class : la valeur reçue ici est déjà une instance
        // Poste, pas une chaîne brute — Poste::from() planterait dessus.
        return DelegationPoste::query()
            ->where('delegataire_id', $utilisateur->id)
            ->activesLe(Date::today())
            ->latest('id')
            ->value('poste');
    }

    /**
     * Vrai si l'action a été rendue possible par une délégation plutôt que
     * par le poste propre de l'utilisateur — sert à marquer la transition
     * "en intérim", même principe que DgDisponibilite.
     */
    public function agitEnInterimPour(User $utilisateur, array $postesAutorises): bool
    {
        if ($utilisateur->poste !== null && in_array($utilisateur->poste, $postesAutorises, true)) {
            return false;
        }

        return $this->posteDelegueAujourdhui($utilisateur) !== null;
    }
}
