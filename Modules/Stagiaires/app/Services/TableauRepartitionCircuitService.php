<?php

namespace Modules\Stagiaires\Services;

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
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Enums\TableauRepartitionStatut;
use Modules\Stagiaires\Exceptions\TableauRepartitionTransitionException;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Models\TableauRepartition;
use Modules\Stagiaires\Models\TableauRepartitionLigne;

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

    public function creer(User $dfp, string $periodeDebut, string $periodeFin): TableauRepartition
    {
        return TableauRepartition::query()->create([
            'direction_id' => $this->directionDfp()->id,
            'redacteur_id' => $dfp->id,
            'periode_debut' => $periodeDebut,
            'periode_fin' => $periodeFin,
            'statut' => TableauRepartitionStatut::BROUILLON,
        ]);
    }

    public function ajouterLigne(
        TableauRepartition $tableau,
        Stagiaire $stagiaire,
        int $directionAccueilProposeeId,
        string $dateDebutProposee,
        string $dateFinProposee,
        string $encadrantPressenti,
    ): TableauRepartitionLigne {
        if (! $tableau->modifiable()) {
            throw new TableauRepartitionTransitionException('Ce tableau est déjà soumis : il ne peut plus être modifié.');
        }

        if ($stagiaire->statut !== StagiaireStatut::EN_ATTENTE_AFFECTATION) {
            throw new TableauRepartitionTransitionException(
                "Ce dossier est au statut «{$stagiaire->statut->label()}» : seul un dossier «En attente d'affectation» peut être ajouté à un tableau."
            );
        }

        if (! $this->affectationRules->estEligible($directionAccueilProposeeId)) {
            throw new TableauRepartitionTransitionException("Direction d'accueil proposée non éligible (inactive ou inexistante).");
        }

        return TableauRepartitionLigne::query()->create([
            'tableau_repartition_id' => $tableau->id,
            'stagiaire_id' => $stagiaire->id,
            'direction_accueil_proposee_id' => $directionAccueilProposeeId,
            'date_debut_proposee' => $dateDebutProposee,
            'date_fin_proposee' => $dateFinProposee,
            'encadrant_pressenti' => $encadrantPressenti,
        ]);
    }

    public function retirerLigne(TableauRepartition $tableau, TableauRepartitionLigne $ligne): void
    {
        if (! $tableau->modifiable()) {
            throw new TableauRepartitionTransitionException('Ce tableau est déjà soumis : il ne peut plus être modifié.');
        }

        if ($ligne->tableau_repartition_id !== $tableau->id) {
            throw new TableauRepartitionTransitionException("Cette ligne n'appartient pas à ce tableau.");
        }

        $ligne->delete();
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
     * Contrairement à CourrierCircuitService::rendreAvisDg(), l'approbation
     * ne rend rien effectif sur les stagiaires (direction réelle, dates,
     * notifications) : c'est le Lot 5 qui verrouille et câble ce geste,
     * volontairement laissé hors de ce lot.
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
