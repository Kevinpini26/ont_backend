<?php

namespace Modules\Courrier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Contracts\CircuitTransitionRules;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\SensCourrier;
use Modules\Courrier\Exceptions\TransitionNonAutoriseeException;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\CourrierTransition;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Notifications\CourrierDgNotification;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Contracts\NotificationService;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;
use Modules\Kernel\Support\DgAuthorityResolver;

/**
 * Primitives transverses au circuit courrier, extraites de
 * CourrierCircuitService (voir ce fichier pour l'orchestration publique) :
 * partagées par CourrierReceptionService, CourrierTriDispatchService,
 * CourrierReponseService et CourrierEnvoiService, elles n'ont qu'un seul
 * endroit de vérité ici plutôt que d'être dupliquées dans chacun.
 */
class CourrierCircuitHelpers
{
    public function __construct(
        private readonly CircuitTransitionRules $regles,
        private readonly AuditLogger $audit,
        private readonly DelegationResolver $delegations,
        private readonly DgAuthorityResolver $autoriteDg,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Trace chaque changement de statut : alimente le calcul du temps moyen
     * de traitement par étape du circuit (dashboard courrier). $utilisateur
     * est nul uniquement pour un dépôt public (aucun agent à l'origine).
     *
     * Chaque transition est historisée. Un destinataire n'est renseigné que
     * lorsqu'elle remet le dossier à un autre poste ou à un relecteur précis;
     * un changement local reste traçable sans créer de décharge.
     */
    public function tracerTransition(Courrier $courrier, ?User $utilisateur, ?int $bordereauLotId = null, ?string $instruction = null): void
    {
        $ancienStatut = CourrierTransition::query()
            ->where('courrier_id', $courrier->id)
            ->latest('id')
            ->value('nouveau_statut');
        $posteEmetteur = $utilisateur?->poste?->value;
        $destinatairePoste = null;
        $destinataireUserId = null;

        if ($courrier->sens === SensCourrier::SORTANT && $courrier->statut === CourrierStatut::PROJET_A_REDIGER) {
            // Brouillon personnel de l'assistant assigné : aucune remise à
            // décharger avant la soumission nominative au relecteur.
        } elseif ($courrier->enAttenteValidationRelecteur()) {
            $destinataireUserId = $courrier->relecteur_id;
        } else {
            $postesAutorises = $this->regles->postesPourTransitionResolue(
                $courrier->statut,
                $courrier->necessite_avis_dg,
                $courrier->initie_par_dg,
                $courrier->sens === SensCourrier::SORTANT,
                ['type' => $courrier->type?->value],
            );
            $destinatairePoste = ($postesAutorises[0] ?? null)?->value;
        }

        if ($destinataireUserId === null && $destinatairePoste !== null && $destinatairePoste === $posteEmetteur) {
            $destinatairePoste = null;
        }

        CourrierTransition::query()->create([
            'courrier_id' => $courrier->id,
            'statut' => $courrier->statut,
            'ancien_statut' => $ancienStatut,
            'nouveau_statut' => $courrier->statut,
            'tour' => $courrier->tour,
            'changed_by_id' => $utilisateur?->id,
            'expediteur_poste' => $posteEmetteur,
            'instruction' => $instruction,
            // Proxy volontairement simple : "cet utilisateur détient une
            // délégation active aujourd'hui", pas une vérification que
            // CETTE transition précise en dépendait — reconstituer ce fait
            // précis demanderait de faire transiter le résultat
            // d'assertTransitionAutorisee() jusqu'ici. Assez fidèle en
            // pratique : un utilisateur en délégation agit rarement aussi
            // sous son propre poste le même jour.
            'agi_en_interim' => $utilisateur !== null && ($this->autoriteDg->source($utilisateur) === 'interim_dga' || $this->delegations->posteDelegueAujourdhui($utilisateur) !== null),
            'destinataire_poste' => $destinatairePoste,
            'destinataire_user_id' => $destinataireUserId,
            'bordereau_lot_id' => $bordereauLotId,
            'created_at' => now(),
        ]);
    }

    /**
     * Recharge le courrier avec un verrou de ligne, à appeler en tout
     * premier à l'intérieur d'une DB::transaction() : si deux requêtes
     * tentent la même transition au même instant, la seconde attend que la
     * première committe puis relit l'état réel avant de revalider — jamais
     * un statut déjà périmé porté par l'instance reçue en paramètre de la
     * méthode publique. Même pattern que
     * StagiaireCircuitService::lockStagiaireFrais().
     */
    public function lockCourrierFrais(Courrier $courrier): Courrier
    {
        $frais = Courrier::query()->lockForUpdate()->findOrFail($courrier->id);
        $this->assertCourrierActif($frais);

        return $frais;
    }

    /** Un courrier annulé ou mis en attente ne peut plus avancer dans le circuit. */
    public function assertCourrierActif(Courrier $courrier): void
    {
        if ($courrier->estAnnule()) {
            throw ValidationException::withMessages(['courrier' => 'Ce courrier a été annulé : il ne peut plus avancer dans le circuit.']);
        }
        if ($courrier->estSuspendu()) {
            throw ValidationException::withMessages(['courrier' => 'Ce courrier est mis en attente : reprenez-le avant de continuer.']);
        }
    }

    public function auditerIdentite(Courrier $courrier, ?User $acteur, string $type, string $valeur): void
    {
        $this->audit->enregistrer("courrier.{$type}_attribue", $courrier, $acteur, [
            'type_identite' => $type,
            'valeur' => $valeur,
            'attribuee_at' => now()->toISOString(),
        ]);
    }

    public function assertDechargeDonnee(Courrier $courrier): void
    {
        if ($courrier->enTransit()) {
            throw TransitionNonAutoriseeException::dechargeNonDonnee();
        }
    }

    /**
     * `$contexte` porte les valeurs nécessaires à la résolution quand le
     * statut courant a plusieurs destinations candidates (voir
     * CircuitTransitionRules::statutSuivant()) — vide pour la plupart des
     * appels, à un seul statut de destination possible.
     *
     * @param  array<string, mixed>  $contexte
     */
    public function assertTransitionAutorisee(Courrier $courrier, User $utilisateur, CourrierStatut $statutCible, array $contexte = []): void
    {
        $this->assertCourrierActif($courrier);
        $this->assertDechargeDonnee($courrier);

        $estSortant = $courrier->sens === SensCourrier::SORTANT;
        $statutAttendu = $this->regles->statutSuivant($courrier->statut, $courrier->necessite_avis_dg, $courrier->initie_par_dg, $estSortant, $contexte);

        if ($statutAttendu === null || $statutAttendu !== $statutCible) {
            throw TransitionNonAutoriseeException::sautDetape();
        }

        $postesAutorises = $this->regles->postesAutorises($courrier->statut, $courrier->necessite_avis_dg, $courrier->initie_par_dg, $estSortant);

        // Un utilisateur qui n'occupe pas lui-même l'un des postes
        // autorisés peut malgré tout agir s'il en détient une délégation
        // active aujourd'hui (voir DelegationPoste) — aucun poste du
        // circuit ne doit bloquer le flux par la seule absence de son
        // titulaire.
        if (! $this->delegations->utilisateurHabilite($utilisateur, $postesAutorises)) {
            throw TransitionNonAutoriseeException::posteNonHabilite();
        }
    }

    public function assertRelecteurNonSec2(int $relecteurId): void
    {
        if (User::query()->whereKey($relecteurId)->where('poste', Poste::SECRETARIAT_2)->exists()) {
            throw ValidationException::withMessages(['relecteur_id' => 'SEC2 ne peut pas être désigné comme relecteur dans un nouveau circuit.']);
        }
    }

    public function notifierDgApresCommit(Courrier $courrier, ?string $evenement = null): void
    {
        $evenement ??= DocumentProduitDirection::query()->where('courrier_id', $courrier->id)->exists()
            ? 'retour_direction_a_decider' : 'pret_a_traiter';
        DB::afterCommit(function () use ($courrier, $evenement) {
            $interim = $this->autoriteDg->interimOuvert();
            if ($interim !== null) {
                $destinataire = User::query()->find($interim->dga_interimaire_id);
                if ($destinataire !== null) {
                    $this->notifications->notifier($destinataire, new CourrierDgNotification($courrier, $evenement));
                }

                return;
            }
            User::query()->where('poste', Poste::DG)->each(fn (User $dg) => $this->notifications->notifier($dg, new CourrierDgNotification($courrier, $evenement)));
        });
    }
}
