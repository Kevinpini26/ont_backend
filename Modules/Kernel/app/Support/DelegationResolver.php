<?php

namespace Modules\Kernel\Support;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\DelegationPoste;
use Modules\Kernel\Models\DgInterim;
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
        if (in_array(Poste::DG, $postesAutorises, true)) {
            $interim = DgInterim::query()->whereNull('ended_at')->first();
            if ($interim !== null) {
                if ($utilisateur->id === $interim->dga_interimaire_id) {
                    return true;
                }
                $postesAutorises = array_values(array_filter($postesAutorises, fn (Poste $poste) => $poste !== Poste::DG));
            }
        }
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
     * (erreur de saisie), l'accès est refusé.
     */
    public function posteDelegueAujourdhui(User $utilisateur): ?Poste
    {
        // ->value('poste') hydrate quand même le modèle pour appliquer le
        // cast Poste::class : la valeur reçue ici est déjà une instance
        // Poste, pas une chaîne brute — Poste::from() planterait dessus.
        $query = DelegationPoste::query()
            ->where('delegataire_id', $utilisateur->id)
            ->activesLe(Date::today())
            ->orderBy('id');
        // Une action transactionnelle conserve la délégation verrouillée
        // jusqu'au commit : une révocation concurrente ne peut pas prendre
        // effet entre la vérification du droit et l'action.
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        $delegations = $query->get();
        if ($delegations->count() !== 1) {
            return null;
        }
        $delegation = $delegations->first();
        $memePoste = DelegationPoste::query()->where('poste', $delegation->poste)
            ->activesLe(Date::today())->orderBy('id');
        if (DB::transactionLevel() > 0) {
            $memePoste->lockForUpdate();
        }
        if (count($memePoste->get()->all()) !== 1) {
            return null;
        }

        if ($delegation->poste === Poste::DG && DgInterim::query()->whereNull('ended_at')->exists()) {
            return null;
        }

        return $delegation->poste;
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
