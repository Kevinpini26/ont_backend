<?php

namespace Modules\Stagiaires\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Courrier\Contracts\NumeroGenerator;
use Modules\Courrier\Enums\AvisDg;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\CourrierTransition;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DgDisponibilite;
use Modules\Stagiaires\Contracts\AffectationRules;
use Modules\Stagiaires\Contracts\TableauRepartitionPdfGenerator;
use Modules\Stagiaires\Enums\IssueProposee;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Enums\TableauRepartitionStatut;
use Modules\Stagiaires\Exceptions\TableauRepartitionTransitionException;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Models\TableauRepartition;
use Modules\Stagiaires\Models\TableauRepartitionLigne;
use Modules\Stagiaires\Support\AvertissementsLigneTableau;

/**
 * Circuit du tableau de répartition (Lot 4) — "calqué sur celui du
 * courrier" au sens architectural (même trajet Réception -> DG, même
 * notion de tour de boucle sur renvoi), mais un service à part entière :
 * CourrierCircuitService/CircuitTransitionRules sont fortement couplés à
 * la classe Courrier et à ses statuts propres (recherche préalable), rien
 * n'y est réutilisable tel quel pour un autre objet. Le trajet lui-même
 * est en revanche bien porté par un vrai `Courrier` synthétique
 * (type=TABLEAU_REPARTITION), pour hériter de `tour` et de l'historique
 * `courrier_transitions` déjà éprouvés — sans le bordereau/décharge du
 * courrier ordinaire : chaque transition ici laisse `destinataire_*` nul,
 * ce qui suffit (voir Courrier::enTransit()) à ne jamais bloquer l'étape
 * suivante. Un tableau n'est jamais remis "de la main à la main" comme un
 * pli de courrier ; une simple trace horodatée par passage suffit.
 */
class TableauRepartitionCircuitService
{
    public function __construct(
        private readonly NumeroGenerator $numeros,
        private readonly AffectationRules $affectationRules,
        private readonly TableauRepartitionPdfGenerator $pdf,
        private readonly AuditLogger $audit,
        private readonly StagiaireCircuitService $stagiaires,
        private readonly AvertissementsLigneTableau $avertissements,
    ) {}

    private function assertStatut(TableauRepartition $tableau, TableauRepartitionStatut $attendu): void
    {
        if ($tableau->statut !== $attendu) {
            throw new TableauRepartitionTransitionException(
                "Action impossible : le tableau est au statut «{$tableau->statut->label()}», pas «{$attendu->label()}»."
            );
        }
    }

    /**
     * La DFP n'est jamais rattachée à une direction (voir
     * UserRole::rolesRequiringDirection(), AGENT_DFP absent) — la
     * direction "qui établit" le tableau est donc résolue par convention
     * de code plutôt que lue sur l'utilisateur, voir
     * config('stagiaires.direction_dfp_code').
     */
    private function directionDfp(): Direction
    {
        return Direction::query()
            ->where('code', config('stagiaires.direction_dfp_code'))
            ->firstOrFail();
    }

    /**
     * Lot A : plusieurs tableaux par période sont autorisés par défaut,
     * dont des compléments pour les dossiers arrivés après coup — voir
     * config('stagiaires.tableau_un_seul_par_periode') et
     * docs/questions-ont.md. Un tableau "en cours" est tout ce qui n'est
     * pas encore approuvé (brouillon compris) : un tableau approuvé ne
     * bloque jamais l'ouverture de la période suivante.
     */
    public function creer(User $dfp, string $periodeDebut, string $periodeFin): TableauRepartition
    {
        if (config('stagiaires.tableau_un_seul_par_periode', false)) {
            $existeDeja = TableauRepartition::query()
                ->where('periode_debut', $periodeDebut)
                ->where('periode_fin', $periodeFin)
                ->where('statut', '!=', TableauRepartitionStatut::APPROUVE)
                ->exists();

            if ($existeDeja) {
                throw new TableauRepartitionTransitionException(
                    'Un tableau est déjà en cours pour cette période (config("stagiaires.tableau_un_seul_par_periode")).'
                );
            }
        }

        return TableauRepartition::query()->create([
            'direction_id' => $this->directionDfp()->id,
            'redacteur_id' => $dfp->id,
            'periode_debut' => $periodeDebut,
            'periode_fin' => $periodeFin,
            'statut' => TableauRepartitionStatut::BROUILLON,
        ]);
    }

    /**
     * Lot A : un dossier n'est éligible à un tableau que si son courrier
     * porteur a été imputé à la DFP par la DG — jamais ressaisi, jamais
     * choisi librement par la DFP dans toute la base des demandes de
     * stage. `en_attente_affectation` reste une condition nécessaire (la
     * DFP a examiné le dossier, voir examinerDossier()) mais pas
     * suffisante.
     */
    public function estEligibleAuTableau(Stagiaire $stagiaire): bool
    {
        if ($stagiaire->statut !== StagiaireStatut::EN_ATTENTE_AFFECTATION) {
            return false;
        }

        /** @var Courrier|null $courrier */
        $courrier = $stagiaire->courrier;

        if ($courrier === null) {
            return false;
        }

        return $courrier->imputations()
            ->where('direction_id', $this->directionDfp()->id)
            ->exists();
    }

    /**
     * @return Collection<int, Stagiaire>
     */
    public function dossiersEligibles(): Collection
    {
        $directionDfpId = $this->directionDfp()->id;

        return Stagiaire::query()
            ->where('statut', StagiaireStatut::EN_ATTENTE_AFFECTATION)
            ->whereHas('courrier.imputations', fn ($q) => $q->where('direction_id', $directionDfpId))
            ->with('courrier')
            ->orderBy('created_at')
            ->get();
    }

    public function ajouterLigne(
        TableauRepartition $tableau,
        Stagiaire $stagiaire,
        User $dfp,
        int $directionAccueilProposeeId,
        string $dateDebutProposee,
        string $dateFinProposee,
        string $encadrantPressenti,
        IssueProposee $issueProposee,
        ?string $motifNonRetenu,
        ?string $motifNonRetenuLibre,
    ): TableauRepartitionLigne {
        if (! $tableau->modifiable()) {
            throw new TableauRepartitionTransitionException('Ce tableau est déjà soumis : il ne peut plus être modifié.');
        }

        if (! $this->estEligibleAuTableau($stagiaire)) {
            throw new TableauRepartitionTransitionException(
                "Ce dossier n'est pas éligible : il doit être «En attente d'affectation» et son courrier doit avoir été imputé à la DFP par la Direction Générale."
            );
        }

        if (! $this->affectationRules->estEligible($directionAccueilProposeeId)) {
            throw new TableauRepartitionTransitionException("Direction d'accueil proposée non éligible (inactive ou inexistante).");
        }

        if ($issueProposee === IssueProposee::NON_RETENU && $motifNonRetenu === null) {
            throw new TableauRepartitionTransitionException('Un motif est requis pour une issue "non retenu".');
        }

        return DB::transaction(function () use (
            $tableau, $stagiaire, $dfp, $directionAccueilProposeeId, $dateDebutProposee, $dateFinProposee,
            $encadrantPressenti, $issueProposee, $motifNonRetenu, $motifNonRetenuLibre,
        ) {
            $ligne = TableauRepartitionLigne::query()->create([
                'tableau_repartition_id' => $tableau->id,
                'stagiaire_id' => $stagiaire->id,
                'direction_accueil_proposee_id' => $directionAccueilProposeeId,
                'date_debut_proposee' => $dateDebutProposee,
                'date_fin_proposee' => $dateFinProposee,
                'encadrant_pressenti' => $encadrantPressenti,
                'issue_proposee' => $issueProposee,
                'motif_non_retenu' => $motifNonRetenu,
                'motif_non_retenu_libre' => $motifNonRetenuLibre,
            ]);

            // Lot A : les contrôles n'ont jamais bloqué la création
            // ci-dessus — seule leur présence est tracée, pour que le
            // passage en force reste visible sans jamais être un obstacle.
            $avertissements = $this->avertissements->pour($ligne->fresh(['tableau', 'stagiaire']));
            if ($avertissements !== []) {
                $this->audit->enregistrer('tableau_repartition.ligne_ajoutee_avec_avertissement', $ligne, $dfp, [
                    'codes' => array_column($avertissements, 'code'),
                ]);
            }

            // Lot 5/B : "en instruction" tant que le tableau qui le porte
            // n'est pas approuvé — rien ne sort avant (voir
            // StagiairePolicy, StagiaireCircuitService::validerArrivee()
            // toujours gardée par le statut AFFECTE).
            $stagiaire->statut = StagiaireStatut::EN_INSTRUCTION;
            $stagiaire->save();

            return $ligne;
        });
    }

    /**
     * @param  list<array{stagiaire_id: int, direction_accueil_proposee_id: int, date_debut_proposee: string, date_fin_proposee: string, encadrant_pressenti: string, issue_proposee?: string, motif_non_retenu?: ?string, motif_non_retenu_libre?: ?string}>  $lignes
     * @return list<TableauRepartitionLigne>
     */
    public function ajouterLignesEnLot(TableauRepartition $tableau, User $dfp, array $lignes): array
    {
        return DB::transaction(function () use ($tableau, $dfp, $lignes) {
            $resultat = [];

            foreach ($lignes as $donnees) {
                $stagiaire = Stagiaire::query()->findOrFail($donnees['stagiaire_id']);

                $resultat[] = $this->ajouterLigne(
                    $tableau,
                    $stagiaire,
                    $dfp,
                    $donnees['direction_accueil_proposee_id'],
                    $donnees['date_debut_proposee'],
                    $donnees['date_fin_proposee'],
                    $donnees['encadrant_pressenti'],
                    IssueProposee::from($donnees['issue_proposee'] ?? IssueProposee::RETENU->value),
                    $donnees['motif_non_retenu'] ?? null,
                    $donnees['motif_non_retenu_libre'] ?? null,
                );
            }

            return $resultat;
        });
    }

    public function retirerLigne(TableauRepartition $tableau, TableauRepartitionLigne $ligne): void
    {
        if (! $tableau->modifiable()) {
            throw new TableauRepartitionTransitionException('Ce tableau est déjà soumis : il ne peut plus être modifié.');
        }

        if ($ligne->tableau_repartition_id !== $tableau->id) {
            throw new TableauRepartitionTransitionException("Cette ligne n'appartient pas à ce tableau.");
        }

        DB::transaction(function () use ($ligne) {
            $stagiaire = $ligne->stagiaire()->lockForUpdate()->firstOrFail();
            $ligne->delete();

            // Proposition retirée : retour à "en attente d'affectation",
            // sauf si le dossier a par ailleurs déjà avancé autrement
            // (jamais le cas en pratique tant qu'il est en instruction).
            if ($stagiaire->statut === StagiaireStatut::EN_INSTRUCTION) {
                $stagiaire->statut = StagiaireStatut::EN_ATTENTE_AFFECTATION;
                $stagiaire->save();
            }
        });
    }

    /**
     * Crée le courrier synthétique qui porte le trajet du tableau et le
     * place chez la Réception — même rôle d'entrée que "recu" pour un
     * courrier ordinaire, mais aussi de retour après un renvoi DG (voir
     * representerDg()).
     */
    public function soumettre(TableauRepartition $tableau, User $dfp): TableauRepartition
    {
        $this->assertStatut($tableau, TableauRepartitionStatut::BROUILLON);

        if ($tableau->lignes()->doesntExist()) {
            throw new TableauRepartitionTransitionException('Un tableau vide ne peut pas être soumis : ajoutez au moins une demande.');
        }

        return DB::transaction(function () use ($tableau, $dfp) {
            $courrier = Courrier::query()->create([
                'numero_accuse_reception' => $this->numeros->genererAccuseReception(),
                'objet' => "Tableau de répartition — {$tableau->periode_debut->format('d/m/Y')} au {$tableau->periode_fin->format('d/m/Y')}",
                'type' => CourrierType::TABLEAU_REPARTITION,
                'statut' => CourrierStatut::TABLEAU_CHEZ_RECEPTION,
                'necessite_avis_dg' => true,
                'initie_par_dg' => false,
                'created_by' => $dfp->id,
            ]);

            $tableau->courrier_id = $courrier->id;
            $tableau->statut = TableauRepartitionStatut::CHEZ_RECEPTION;
            $tableau->save();

            $this->tracer($courrier, $dfp);

            return $tableau;
        });
    }

    /**
     * La Réception présente le tableau à la DG — appelée aussi bien pour
     * la toute première présentation que pour re-présenter après un renvoi
     * avec observations (voir rendreAvis()) : le tour n'est incrémenté que
     * dans ce second cas (avis_dg déjà renseigné signale un renvoi
     * antérieur).
     */
    public function representerDg(TableauRepartition $tableau, User $reception): TableauRepartition
    {
        $this->assertStatut($tableau, TableauRepartitionStatut::CHEZ_RECEPTION);

        if ($reception->poste !== Poste::RECEPTION) {
            throw new TableauRepartitionTransitionException('Seule la Réception peut présenter le tableau à la Direction Générale.');
        }

        return DB::transaction(function () use ($tableau, $reception) {
            /** @var Courrier $courrier */
            $courrier = $tableau->courrier()->firstOrFail();

            if ($courrier->avis_dg !== null) {
                $courrier->tour += 1;
            }

            $courrier->statut = CourrierStatut::TABLEAU_EN_ATTENTE_AVIS_DG;
            $courrier->save();

            $tableau->statut = TableauRepartitionStatut::EN_ATTENTE_AVIS_DG;
            $tableau->save();

            $this->tracer($courrier, $reception);

            return $tableau;
        });
    }

    /**
     * La DG approuve en bloc, ou renvoie avec observations (le dossier
     * revient chez la Réception, tour suivant — voir representerDg()).
     * L'approbation est la seule action qui rend les propositions du
     * tableau effectives (Lot 5, "verrouiller la diffusion") : chaque
     * ligne devient une vraie affectation (voir
     * StagiaireCircuitService::affecter()), avec ses effets — note
     * d'affectation, notification à la direction — jusque-là aucun canal
     * ne s'ouvre (voir StagiaireStatut::EN_INSTRUCTION). Un renvoi ne
     * déclenche rien de tout cela.
     */
    public function rendreAvis(TableauRepartition $tableau, User $dg, bool $approuve, ?string $observations): TableauRepartition
    {
        $this->assertStatut($tableau, TableauRepartitionStatut::EN_ATTENTE_AVIS_DG);

        if (! $approuve && ($observations === null || trim($observations) === '')) {
            throw new TableauRepartitionTransitionException('Un renvoi doit être motivé par une observation.');
        }

        // Même garde d'intérim dynamique que CourrierCircuitService::rendreAvisDg()
        // — dupliquée plutôt que sur-abstraite pour ce seul autre appelant.
        $enInterim = $dg->poste === Poste::DGA;
        if ($dg->poste !== Poste::DG && ! $enInterim) {
            throw new TableauRepartitionTransitionException('Seule la Direction Générale peut se prononcer sur ce tableau.');
        }
        if ($enInterim && DgDisponibilite::estDisponible()) {
            throw new TableauRepartitionTransitionException('La DGA ne peut agir que lorsque la DG est marquée indisponible.');
        }

        return DB::transaction(function () use ($tableau, $dg, $approuve, $observations, $enInterim) {
            /** @var Courrier $courrier */
            $courrier = $tableau->courrier()->lockForUpdate()->firstOrFail();

            $courrier->avis_dg = $approuve ? AvisDg::FAVORABLE : AvisDg::RESERVE;
            $courrier->avis_dg_commentaire = $observations;
            $courrier->avis_dg_rendu_at = now();
            $courrier->avis_dg_rendu_par_id = $dg->id;
            $courrier->avis_dg_rendu_en_interim = $enInterim;

            if (! $approuve) {
                $courrier->statut = CourrierStatut::TABLEAU_CHEZ_RECEPTION;
                $courrier->save();

                $tableau->statut = TableauRepartitionStatut::CHEZ_RECEPTION;
                $tableau->save();

                $this->tracer($courrier, $dg);

                $this->audit->enregistrer('tableau_repartition.renvoye', $tableau, $dg, ['observations' => $observations]);

                return $tableau;
            }

            $this->assertAucunDoublonApprouve($tableau);

            // Lot A/B : c'est ici, et seulement ici, que chaque proposition
            // devient réelle — une ligne, un appel, à l'intérieur de cette
            // même transaction : si l'une échoue (quota atteint
            // entre-temps), l'approbation entière échoue "en bloc", rien
            // n'est décidé à moitié. redacteur (la DFP qui a composé le
            // tableau) reste l'auteur de la décision, pas la DG qui ne fait
            // qu'approuver le lot.
            /** @var User $redacteur */
            $redacteur = $tableau->redacteur()->firstOrFail();
            $stagiairesANotifier = [];

            foreach ($tableau->lignes()->with('stagiaire')->get() as $ligne) {
                if ($ligne->issue_proposee === IssueProposee::NON_RETENU) {
                    $stagiairesANotifier[] = $this->stagiaires->nonRetenu(
                        $ligne->stagiaire,
                        $redacteur,
                        $ligne->motif_non_retenu,
                        $ligne->motif_non_retenu_libre,
                    );

                    continue;
                }

                $stagiairesANotifier[] = $this->stagiaires->affecter(
                    $ligne->stagiaire,
                    $redacteur,
                    $ligne->direction_accueil_proposee_id,
                    $ligne->encadrant_pressenti,
                );
            }

            // Après commit uniquement : la diffusion (Lot B) ne doit
            // jamais partir pour une approbation finalement annulée par un
            // rollback (ex. doublon détecté par une transaction
            // concurrente).
            DB::afterCommit(function () use ($stagiairesANotifier) {
                foreach ($stagiairesANotifier as $stagiaire) {
                    $this->stagiaires->notifierIssue($stagiaire);
                }
            });

            $courrier->statut = CourrierStatut::TABLEAU_APPROUVE;
            $courrier->save();

            $tableau->statut = TableauRepartitionStatut::APPROUVE;
            $tableau->approuve_par_id = $dg->id;
            $tableau->approuve_at = now();
            $tableau->save();

            $this->tracer($courrier, $dg);

            $tableau->pdf_chemin = $this->pdf->generer($tableau->fresh(['lignes.stagiaire', 'lignes.directionAccueilProposee', 'direction', 'redacteur']));
            $tableau->save();

            $this->audit->enregistrer('tableau_repartition.approuve', $tableau, $dg);

            return $tableau;
        });
    }

    /**
     * Une demande de stage ne peut figurer que dans un seul tableau
     * approuvé (contrainte inter-tables, pas exprimable en index SQL —
     * voir la migration) : sous verrou, faute de quoi deux approbations
     * concurrentes pourraient chacune passer la vérification avant que
     * l'une des deux n'ait sauvegardé.
     */
    private function assertAucunDoublonApprouve(TableauRepartition $tableau): void
    {
        $stagiaireIds = $tableau->lignes()->lockForUpdate()->pluck('stagiaire_id');

        $conflits = TableauRepartitionLigne::query()
            ->whereIn('stagiaire_id', $stagiaireIds)
            ->where('tableau_repartition_id', '!=', $tableau->id)
            ->whereHas('tableau', fn ($q) => $q->where('statut', TableauRepartitionStatut::APPROUVE))
            ->with('stagiaire')
            ->lockForUpdate()
            ->get();

        if ($conflits->isNotEmpty()) {
            $noms = $conflits->pluck('stagiaire.nom')->unique()->implode(', ');
            throw new TableauRepartitionTransitionException(
                "Approbation refusée : déjà présent(e) dans un autre tableau approuvé — {$noms}."
            );
        }
    }

    /**
     * Trace un passage sans bordereau (destinataire_* nul) — voir la
     * docblock de classe. `tour` est lu depuis le courrier, déjà à jour à
     * cet instant (posé juste avant l'appel).
     */
    private function tracer(Courrier $courrier, User $auteur): void
    {
        CourrierTransition::query()->create([
            'courrier_id' => $courrier->id,
            'statut' => $courrier->statut,
            'tour' => $courrier->tour,
            'changed_by_id' => $auteur->id,
            'created_at' => now(),
        ]);
    }
}
