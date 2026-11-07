<?php

namespace Modules\Courrier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Courrier\Contracts\NumeroGenerator;
use Modules\Courrier\Enums\AvisDg;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Enums\DegreUrgence;
use Modules\Courrier\Enums\MentionImputation;
use Modules\Courrier\Enums\ModeReception;
use Modules\Courrier\Events\CourrierStageAvisFavorable;
use Modules\Courrier\Exceptions\TransitionNonAutoriseeException;
use Modules\Courrier\Models\BordereauLot;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\CourrierTransition;
use Modules\Courrier\Models\ReorientationTri;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;
use Modules\Kernel\Support\DgAuthorityResolver;

/**
 * Tri du Secrétariat 01, dispatch vers les directions et avis DG — extrait
 * de CourrierCircuitService, voir ce fichier pour l'orchestration publique.
 */
class CourrierTriDispatchService
{
    public function __construct(
        private readonly NumeroGenerator $numeros,
        private readonly AuditLogger $audit,
        private readonly DelegationResolver $delegations,
        private readonly DgAuthorityResolver $autoriteDg,
        private readonly DispatchCourrierService $dispatchs,
        private readonly CourrierCircuitHelpers $helpers,
    ) {}

    /**
     * Après la remise initiale par la Réception, le Secrétariat 01 acquitte
     * le bordereau puis place le dossier dans sa file de tri.
     */
    public function transmettreTri(Courrier $courrier, User $utilisateur, ?string $instruction = null): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur, $instruction) {
            $courrier = $this->helpers->lockCourrierFrais($courrier);

            // Tout document reçu passe par la Réception, qui l'enregistre
            // (numéro d'enregistrement) puis le transmet à SEC1 : un dépôt
            // en ligne ne peut pas être trié avant.
            if ($courrier->statut === CourrierStatut::RECU && $courrier->mode_reception === ModeReception::DEPOT_EN_LIGNE) {
                if ($courrier->enregistre_at === null) {
                    throw ValidationException::withMessages([
                        'courrier' => 'Ce dépôt doit d’abord être enregistré par la Réception avant d’être trié.',
                    ]);
                }
                if (! $courrier->transitions()->exists()) {
                    throw ValidationException::withMessages([
                        'courrier' => 'Ce dépôt doit d’abord être transmis à SEC1 par la Réception.',
                    ]);
                }
            }

            $this->helpers->assertTransitionAutorisee($courrier, $utilisateur, CourrierStatut::EN_ATTENTE_TRI, ['type' => $courrier->type->value]);

            $courrier->statut = CourrierStatut::EN_ATTENTE_TRI;
            $courrier->save();
            $this->helpers->tracerTransition($courrier, $utilisateur, instruction: $instruction);

            $this->audit->enregistrer('courrier.transmis_tri', $courrier, $utilisateur, ['tour' => $courrier->tour]);

            return $courrier;
        });
    }

    /**
     * Lot C, points 1-2 : "la Réception est un point de blocage" tenait au
     * fait que chaque courrier fraîchement créé porte sa propre transition
     * en attente (voir CourrierCircuitHelpers::tracerTransition(),
     * destinataire_poste déjà résolu à la création) — jusqu'ici, le
     * destinataire (Secrétariat 01, le plus souvent) devait accuser
     * réception de chacun UN PAR UN avant de pouvoir seulement commencer à
     * les traiter. Cette méthode ne crée AUCUNE nouvelle transition : elle
     * regroupe sous un même bordereau des transitions déjà existantes,
     * encore en attente, pour qu'une seule décharge (voir
     * accuserReceptionLot()) les débloque toutes. "Trace individuelle par
     * dossier" reste garantie : chaque transition groupée est la même
     * ligne qui existait déjà, seulement étiquetée. Un lot doit être
     * homogène (même poste destinataire) — un bordereau physique ne peut
     * matériellement pas être remis à deux guichets différents à la fois.
     */
    public function grouperEnBordereauLot(array $courrierIds, User $emetteur): BordereauLot
    {
        $courrierIds = array_values(array_unique($courrierIds));

        if ($courrierIds === []) {
            throw TransitionNonAutoriseeException::lotVide();
        }

        return DB::transaction(function () use ($courrierIds, $emetteur) {
            $transitions = CourrierTransition::query()
                ->whereIn('courrier_id', $courrierIds)
                ->whereNull('accuse_reception_at')
                ->whereNotNull('destinataire_poste')
                ->whereNull('bordereau_lot_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('courrier_id');

            if ($transitions->count() !== count($courrierIds)) {
                throw TransitionNonAutoriseeException::sautDetape();
            }

            $postesDestinataires = $transitions->pluck('destinataire_poste')->unique();

            if ($postesDestinataires->count() > 1) {
                throw TransitionNonAutoriseeException::lotHeterogene();
            }

            foreach ($transitions as $transition) {
                $estEmetteur = $transition->changed_by_id === $emetteur->id;
                $estDestinataire = $this->delegations->utilisateurHabilite($emetteur, [Poste::from($transition->destinataire_poste)]);
                if (! $estEmetteur && ! $estDestinataire) {
                    throw TransitionNonAutoriseeException::posteNonHabilite();
                }
            }

            $bordereau = BordereauLot::query()->create([
                'numero' => $this->numeros->genererNumeroBordereauLot(),
                'emetteur_id' => $emetteur->id,
                'poste_destinataire' => $postesDestinataires->first(),
            ]);

            CourrierTransition::query()
                ->whereIn('id', $transitions->pluck('id'))
                ->update(['bordereau_lot_id' => $bordereau->id]);

            return $bordereau;
        });
    }

    /**
     * Décharge du lot en une seule action — mais chaque dossier reçoit
     * individuellement le même accusé (voir CourrierTransition, mises à
     * jour en bloc ci-dessous) : Courrier::enTransit() continue de
     * raisonner dossier par dossier, sans rien savoir d'un "lot".
     */
    public function accuserReceptionLot(BordereauLot $bordereau, User $destinataire): BordereauLot
    {
        return DB::transaction(function () use ($bordereau, $destinataire) {
            $sourceAutorite = $this->autoriteDg->source($destinataire);
            $bordereau = BordereauLot::query()->lockForUpdate()->findOrFail($bordereau->id);
            if ($bordereau->poste_destinataire === Poste::DG && $sourceAutorite === null) {
                throw TransitionNonAutoriseeException::posteNonHabilite();
            }
            if ($bordereau->accuse_reception_at !== null) {
                throw TransitionNonAutoriseeException::dechargeDejaDonnee();
            }

            if (! $this->delegations->utilisateurHabilite($destinataire, [$bordereau->poste_destinataire])) {
                throw TransitionNonAutoriseeException::posteNonHabilite();
            }

            $maintenant = now();

            $bordereau->accuse_reception_at = $maintenant;
            $bordereau->accuse_reception_par_id = $destinataire->id;
            $bordereau->save();

            $bordereau->transitions()->update([
                'accuse_reception_at' => $maintenant,
                'accuse_reception_par_id' => $destinataire->id,
                'destinataire_user_id' => $destinataire->id,
            ]);

            return $bordereau;
        });
    }

    /**
     * C'est ICI, et seulement ici, que le tri par degré d'urgence du
     * Secrétariat 01 est réellement effectué (voir CourrierStatut::EN_ATTENTE_TRI)
     * — jamais un statut à part qui bloquerait le dossier : le courrier
     * reste visible et consultable tant qu'il n'a pas encore été trié
     * (voir Courrier::urgenceTriee(), Setting::valeur('courrier.tri_delai_alerte_heures')
     * pour l'alerte de tri manquant). Une fois trié, seule la DG peut
     * corriger le degré (voir requalifierUrgence()) — cette méthode-ci ne
     * repose jamais sur un degré déjà présent.
     *
     * Lot assistants (voir docs/questions-ont.md) : un degré urgent ou très
     * urgent transmet directement à la DG, comme avant ; un degré normal
     * part désormais au classeur d'attente du Secrétariat 01
     * (CourrierStatut::EN_ATTENTE_CLASSEUR), qui le transmettra lui-même à
     * la DG quand il le jugera bon (voir transmettreDepuisClasseur()) —
     * voir config('courrier.circuit_transitions.complet.en_attente_tri').
     */
    public function transmettreEnAttenteAvisDg(Courrier $courrier, User $utilisateur, DegreUrgence $degreUrgence, ?string $instruction = null): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur, $degreUrgence, $instruction) {
            $courrier = $this->helpers->lockCourrierFrais($courrier);

            $statutCible = match ($degreUrgence) {
                DegreUrgence::URGENT, DegreUrgence::TRES_URGENT => CourrierStatut::EN_ATTENTE_AVIS_DG,
                DegreUrgence::NORMAL => config('courrier.tri.normal_vers_classeur', true)
                    ? CourrierStatut::EN_ATTENTE_CLASSEUR
                    : CourrierStatut::EN_ATTENTE_AVIS_DG,
            };
            // Réglage courrier.tri.normal_vers_classeur désactivé : un degré normal
            // suit le même chemin qu'un courrier signalé (le degré enregistré reste « normal »).
            $degrePourLaRegle = $statutCible === CourrierStatut::EN_ATTENTE_AVIS_DG && $degreUrgence === DegreUrgence::NORMAL
                ? DegreUrgence::URGENT
                : $degreUrgence;
            $this->helpers->assertTransitionAutorisee($courrier, $utilisateur, $statutCible, ['degre_urgence' => $degrePourLaRegle->value]);

            $courrier->degre_urgence = $degreUrgence;
            $courrier->urgence_triee_at = now();
            $courrier->urgence_triee_par_id = $utilisateur->id;
            $courrier->statut = $statutCible;
            $courrier->save();
            $this->helpers->tracerTransition($courrier, $utilisateur, instruction: $instruction);

            $this->audit->enregistrer('courrier.tri_sec1', $courrier, $utilisateur, [
                'degre_urgence' => $degreUrgence->value,
                'statut_arrivee' => $statutCible->value,
            ]);
            if ($statutCible === CourrierStatut::EN_ATTENTE_AVIS_DG) {
                $this->audit->enregistrer('courrier.transmis_dg', $courrier, $utilisateur, ['depuis' => CourrierStatut::EN_ATTENTE_TRI->value]);
                $this->helpers->notifierDgApresCommit($courrier);
            }

            return $courrier;
        });
    }

    /**
     * Le Secrétariat 01 transmet à la DG un dossier qu'il tenait au classeur
     * d'attente (degré d'urgence normal, voir transmettreEnAttenteAvisDg())
     * — à son initiative, jamais automatique : rien d'autre ne fait sortir
     * un dossier du classeur.
     */
    public function transmettreDepuisClasseur(Courrier $courrier, User $utilisateur, ?string $instruction = null): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur, $instruction) {
            $courrier = $this->helpers->lockCourrierFrais($courrier);
            $this->helpers->assertTransitionAutorisee($courrier, $utilisateur, CourrierStatut::EN_ATTENTE_AVIS_DG);

            $courrier->statut = CourrierStatut::EN_ATTENTE_AVIS_DG;
            $courrier->save();
            $this->helpers->tracerTransition($courrier, $utilisateur, instruction: $instruction);

            $this->audit->enregistrer('courrier.transmis_dg', $courrier, $utilisateur, ['depuis' => CourrierStatut::EN_ATTENTE_CLASSEUR->value]);
            $this->helpers->notifierDgApresCommit($courrier);

            return $courrier;
        });
    }

    /**
     * Correction du degré d'urgence après le tri — réservée à la DG et à
     * elle seule (jamais la DGA, y compris en intérim : c'est une décision
     * de fond, pas une action du circuit standard soumise à la garde
     * d'intérim habituelle). Peut s'exercer à tout moment après le tri,
     * quel que soit le statut courant du dossier (un directeur qui reçoit
     * un dossier marqué normal alors qu'il est brûlant doit pouvoir le
     * corriger sans renvoyer le courrier au Secrétariat) — ce n'est donc
     * volontairement pas une transition de circuit (pas d'appel à
     * assertTransitionAutorisee()).
     */
    public function requalifierUrgence(Courrier $courrier, User $dg, DegreUrgence $nouveauDegre): Courrier
    {
        return DB::transaction(function () use ($courrier, $dg, $nouveauDegre) {
            $courrier = $this->helpers->lockCourrierFrais($courrier);

            if (! $courrier->urgenceTriee()) {
                throw TransitionNonAutoriseeException::urgenceNonTriee();
            }

            $ancienDegre = $courrier->degre_urgence;
            $courrier->degre_urgence = $nouveauDegre;
            $courrier->save();

            $this->audit->enregistrer('courrier.urgence_requalifiee', $courrier, $dg, [
                'ancien_degre' => $ancienDegre->value,
                'nouveau_degre' => $nouveauDegre->value,
            ]);

            return $courrier;
        });
    }

    /**
     * Lot C (réorientation du tri) : le signataire qui reçoit un courrier
     * mal orienté vers l'avis DG le renvoie au tri — jamais une faute (le
     * dossier ne boucle pas sur lui-même comme un avis "réservé", il
     * repart carrément vers l'étape précédente), et le tour n'est
     * volontairement pas incrémenté : ce n'est pas un renvoi de fond sur un
     * dossier déjà instruit, juste une correction d'aiguillage avant même
     * que la DG ait commencé à l'examiner. Le geste est cependant tracé
     * dans reorientations_tri (voir ReorientationTri), y compris qui avait
     * trié à l'origine, pour alimenter une statistique de justesse
     * (CourrierPolicy::voirJustesseTri()) — jamais pour désigner une faute,
     * seulement pour objectiver un taux.
     */
    public function renvoyerAuTri(Courrier $courrier, User $utilisateur, ?string $motif): Courrier
    {
        return DB::transaction(function () use ($courrier, $motif, $utilisateur) {
            $courrier = $this->helpers->lockCourrierFrais($courrier);
            $this->helpers->assertDechargeDonnee($courrier);

            if ($courrier->statut !== CourrierStatut::EN_ATTENTE_AVIS_DG) {
                throw TransitionNonAutoriseeException::sautDetape();
            }

            if (! $this->autoriteDg->estAutorite($utilisateur)) {
                throw TransitionNonAutoriseeException::posteNonHabilite();
            }

            $trieParId = CourrierTransition::query()
                ->where('courrier_id', $courrier->id)
                ->where('statut', CourrierStatut::EN_ATTENTE_AVIS_DG)
                ->latest('id')
                ->value('changed_by_id');

            $courrier->statut = CourrierStatut::EN_ATTENTE_TRI;
            $courrier->save();
            $this->helpers->tracerTransition($courrier, $utilisateur);

            ReorientationTri::query()->create([
                'courrier_id' => $courrier->id,
                'trie_par_id' => $trieParId,
                'reoriente_par_id' => $utilisateur->id,
                'motif' => $motif,
                'created_at' => now(),
            ]);

            $this->audit->enregistrer('courrier.reoriente_au_tri', $courrier, $utilisateur, ['motif' => $motif]);

            return $courrier;
        });
    }

    /**
     * La Réception représente à la DG un dossier revenu en "réservé" — seule
     * transition qui incrémente le tour de boucle (voir
     * Courrier::tour, config('courrier.circuit.tours_avant_alerte')).
     */
    public function transmettreRetourVersSec1(Courrier $courrier, User $utilisateur, ?string $instruction = null): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur, $instruction) {
            $courrier = $this->helpers->lockCourrierFrais($courrier);

            if ($courrier->statut === CourrierStatut::RECU && $courrier->mode_reception === ModeReception::DEPOT_EN_LIGNE) {
                if ($utilisateur->poste !== Poste::RECEPTION) {
                    throw TransitionNonAutoriseeException::posteNonHabilite();
                }
                if ($courrier->enregistre_at === null) {
                    throw ValidationException::withMessages([
                        'courrier' => 'Le dépôt doit être enregistré par la Réception avant sa transmission à SEC1.',
                    ]);
                }
                if ($courrier->transitions()->exists()) {
                    throw TransitionNonAutoriseeException::sautDetape();
                }

                $this->helpers->tracerTransition($courrier, $utilisateur, instruction: $instruction);
                $this->audit->enregistrer('courrier.transmis_sec1', $courrier, $utilisateur, [
                    'numero_enregistrement' => $courrier->numero_enregistrement,
                ]);

                return $courrier;
            }

            $this->helpers->assertTransitionAutorisee($courrier, $utilisateur, CourrierStatut::EN_ATTENTE_TRI);

            $courrier->tour += 1;
            $courrier->statut = CourrierStatut::EN_ATTENTE_TRI;
            $courrier->save();
            $this->helpers->tracerTransition($courrier, $utilisateur, instruction: $instruction);

            return $courrier;
        });
    }

    /**
     * Le Secrétariat 02 transmet un courrier imputé (avis DG favorable,
     * voir rendreAvisDg()) au secrétariat de la direction imputée à titre
     * principal (Lot 3). Étape terminale pour ce lot : la suite (tableau de
     * répartition, retour vers la DG) est le Lot 4.
     */
    public function dispatcherVersDirection(Courrier $courrier, User $utilisateur, ?string $instruction = null): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur, $instruction) {
            $courrier = $this->helpers->lockCourrierFrais($courrier);
            $this->helpers->assertTransitionAutorisee($courrier, $utilisateur, CourrierStatut::CHEZ_DIRECTION);

            $courrier->statut = CourrierStatut::CHEZ_DIRECTION;
            $courrier->save();
            $this->helpers->tracerTransition($courrier, $utilisateur, instruction: $instruction);

            return $courrier;
        });
    }

    /**
     * La DGA figure parmi les postes structurellement habilités pour cette
     * étape (voir config('courrier.circuit_transitions')), mais ne peut
     * réellement rendre l'avis que lorsque la DG est marquée indisponible
     * (intérim, voir DgDisponibilite) — la DG, elle, n'est jamais bloquée
     * par ce drapeau. Ce n'est pas une règle de circuit statique (elle
     * dépend d'un état mutable), donc pas encodée dans la table de
     * transitions elle-même.
     */
    public function rendreAvisDg(Courrier $courrier, User $utilisateur, AvisDg $avis, ?string $commentaire): Courrier
    {
        return DB::transaction(function () use ($courrier, $utilisateur, $avis, $commentaire) {
            $courrier = $this->helpers->lockCourrierFrais($courrier);
            if ($courrier->statut !== CourrierStatut::EN_ATTENTE_AVIS_DG) {
                throw TransitionNonAutoriseeException::sautDetape();
            }
            $imputationAutomatiqueDfp = null;

            // Lot A (tableau généré, non ressaisi) : une demande de stage
            // n'a de sens que traitée par la DFP — sans ce filet, un avis
            // favorable rendu sans imputation manuelle laisserait le
            // dossier partir en rédaction interne (projet_a_rediger)
            // au lieu de rejoindre le circuit du tableau de répartition, et
            // la demande resterait orpheline de tout tableau. Configurable
            // (config('stagiaires.imputation_automatique_dfp'), défaut
            // activé) — voir docs/questions-ont.md.
            if (
                $avis === AvisDg::FAVORABLE
                && $courrier->type === CourrierType::DEMANDE_STAGE
                && config('stagiaires.imputation_automatique_dfp', true)
                && $courrier->imputations()->where('est_principale', true)->doesntExist()
            ) {
                $directionDfp = Direction::query()
                    ->where('code', config('stagiaires.direction_dfp_code'))
                    ->first();

                if ($directionDfp !== null) {
                    $courrier->imputations()->create([
                        'direction_id' => $directionDfp->id,
                        'mention' => MentionImputation::POUR_ATTRIBUTION,
                        'est_principale' => true,
                        'imputee_par_id' => $utilisateur->id,
                    ]);
                    $imputationAutomatiqueDfp = $directionDfp;
                }
            }

            // Un avis "réservé" veut dire que la décision n'est pas
            // arrêtée : le dossier boucle (retour_reception, voir
            // config('courrier.circuit_transitions.complet.en_attente_avis_dg'))
            // plutôt que d'avancer comme si une décision avait été prise.
            // Un avis favorable sur un courrier déjà imputé (Lot 3), y
            // compris par le filet ci-dessus, part en dispatch plutôt
            // qu'en rédaction interne de réponse.
            $courrierImpute = $courrier->imputations()->where('est_principale', true)->exists();
            if ($avis === AvisDg::FAVORABLE && $courrierImpute && $imputationAutomatiqueDfp === null) {
                throw ValidationException::withMessages(['courrier' => 'Une nouvelle orientation doit être décidée par le dispatch institutionnel ; les imputations historiques ne sont pas réutilisées comme décision.']);
            }
            if ($imputationAutomatiqueDfp !== null) {
                $this->helpers->assertDechargeDonnee($courrier);
                $courrier = $this->dispatchs->decider($courrier, $utilisateur, [[
                    'type' => 'direction',
                    'direction_id' => $imputationAutomatiqueDfp->id,
                    'instruction' => 'Orientation automatique de la demande de stage vers la DFP après avis favorable DG.',
                ]]);
                $this->audit->enregistrer('courrier.avis_dg_rendu', $courrier, $utilisateur, [
                    'avis' => $avis->value,
                    'orientation_dfp_automatique' => true,
                ]);
                DB::afterCommit(fn () => CourrierStageAvisFavorable::dispatch($courrier));

                return $courrier;
            }
            $statutCible = match (true) {
                $avis === AvisDg::RESERVE => CourrierStatut::RETOUR_RECEPTION,
                $avis === AvisDg::FAVORABLE && $courrierImpute => CourrierStatut::EN_DISPATCH,
                default => CourrierStatut::PROJET_A_REDIGER,
            };
            $this->helpers->assertTransitionAutorisee($courrier, $utilisateur, $statutCible, [
                'avis_dg' => $avis->value,
                'courrier_impute' => $courrierImpute,
            ]);

            $sourceAutorite = $this->autoriteDg->source($utilisateur);
            $enInterim = $sourceAutorite === 'interim_dga';

            if ($sourceAutorite === null) {
                throw TransitionNonAutoriseeException::posteNonHabilite();
            }

            $courrier->avis_dg = $avis;
            $courrier->avis_dg_commentaire = $commentaire;
            $courrier->avis_dg_rendu_at = now();
            $courrier->avis_dg_rendu_par_id = $utilisateur->id;
            $courrier->avis_dg_rendu_en_interim = $enInterim;

            // Les décisions tranchées sans imputation ouvraient auparavant
            // le projet directement sur A. Pour les nouveaux dossiers, A
            // reste désormais sous contrôle DG jusqu'à la création de la
            // mission nominative qui produira D. Les deux statuts legacy
            // restent lisibles et actionnables pour les dossiers qui y sont
            // déjà engagés, mais ne sont plus alimentés ici.
            $ouvrePreparationD = $statutCible === CourrierStatut::PROJET_A_REDIGER;
            $courrier->statut = $ouvrePreparationD ? CourrierStatut::EN_ATTENTE_AVIS_DG : $statutCible;
            $courrier->save();
            if (! $ouvrePreparationD) {
                $this->helpers->tracerTransition($courrier, $utilisateur);
            }

            $this->audit->enregistrer('courrier.avis_dg_rendu', $courrier, $utilisateur, [
                'avis' => $avis->value,
                'interim' => $enInterim,
                'preparation_reponse_d_attendue' => $ouvrePreparationD,
            ]);

            // Après commit uniquement : la fiche stagiaire créée en
            // réaction à cet événement ne doit jamais exister pour un avis
            // finalement annulé par un rollback (ex. verrou expiré, échec
            // d'assertion concurrente). Se déclenche que le courrier soit
            // imputé ou non (statutCible = en_dispatch ou
            // projet_a_rediger) : le Lot 3 ne touche pas à cet
            // événement — "l'avis favorable ne vaut pas acceptation" est le
            // Lot 5, pas celui-ci.
            if ($avis === AvisDg::FAVORABLE && $courrier->type === CourrierType::DEMANDE_STAGE) {
                DB::afterCommit(fn () => CourrierStageAvisFavorable::dispatch($courrier));
            }

            return $courrier;
        });
    }
}
