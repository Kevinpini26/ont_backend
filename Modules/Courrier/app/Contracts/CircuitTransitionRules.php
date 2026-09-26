<?php

namespace Modules\Courrier\Contracts;

use Modules\Courrier\Enums\CourrierStatut;
use Modules\Kernel\Enums\Poste;

/**
 * Point d'extension du circuit courrier : détermine le statut suivant
 * autorisé et le(s) poste(s) habilité(s) à déclencher chaque transition.
 * Une implémentation alternative peut être bindée dans le conteneur pour
 * faire évoluer le circuit sans toucher aux contrôleurs.
 */
interface CircuitTransitionRules
{
    /**
     * Un même statut peut désormais avoir plusieurs destinations candidates
     * (voir config('courrier.circuit_transitions')), chacune gardée par une
     * condition facultative. `$contexte` porte les valeurs nécessaires pour
     * les évaluer — une décision pas encore persistée (ex. l'avis DG sur le
     * point d'être rendu) ou un attribut du courrier utile à une condition
     * (ex. son type) — vide dans la plupart des appels, quand le statut
     * courant n'a qu'une seule suite possible.
     *
     * @param  array<string, mixed>  $contexte
     */
    public function statutSuivant(CourrierStatut $statut, bool $necessiteAvisDg, bool $initieParDg = false, bool $sortant = false, array $contexte = []): ?CourrierStatut;

    /**
     * Union des postes habilités sur TOUTES les destinations candidates
     * depuis ce statut, sans évaluer leurs conditions — reste une
     * habilitation correcte même quand les candidates divergent puisqu'une
     * tentative avec le mauvais poste échouera de toute façon à la
     * résolution du statut cible (voir statutSuivant()). Utilisée pour
     * l'habilitation (CourrierCircuitService::assertTransitionAutorisee(),
     * CourrierPolicy::transmettre()) et pour un filtre abstrait sans
     * instance de courrier (voir
     * VisibiliteStagiairePourCircuitCourrier::appliquerFiltre()), qui ne
     * peut pas évaluer de conditions faute d'un courrier concret.
     *
     * @return Poste[]
     */
    public function postesAutorises(CourrierStatut $statutCourant, bool $necessiteAvisDg, bool $initieParDg = false, bool $sortant = false): array;

    /**
     * Postes de LA transition candidate qui s'appliquerait réellement compte
     * tenu du contexte — résout les conditions, contrairement à
     * postesAutorises(). Replie sur cette même union quand aucune candidate
     * ne se résout avec le contexte fourni (ex. calculer le destinataire
     * d'un bordereau avant qu'une décision ne soit connue) : dans ce cas,
     * toutes les candidates du statut partagent en pratique les mêmes
     * postes. Utilisée pour calculer le destinataire réel d'un bordereau —
     * voir CourrierCircuitService::tracerTransition().
     *
     * @param  array<string, mixed>  $contexte
     * @return Poste[]
     */
    public function postesPourTransitionResolue(CourrierStatut $statutCourant, bool $necessiteAvisDg, bool $initieParDg = false, bool $sortant = false, array $contexte = []): array;

    public function posteDeCreation(): Poste;
}
