<?php

namespace Modules\Stagiaires\Console;

use Illuminate\Console\Command;
use Modules\Kernel\Contracts\AuditLogger;
use Modules\Stagiaires\Enums\IssueProposee;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Enums\TableauRepartitionStatut;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Models\TableauRepartitionLigne;

/**
 * Lot D, point 4 : commande de cohérence nocturne — ne corrige jamais rien
 * elle-même (un désaccord entre deux vérités mérite un regard humain, pas
 * une correction automatique qui pourrait aggraver le problème), se
 * contente de rapporter chaque anomalie détectée à l'administrateur via le
 * journal d'audit existant (voir AuditLogger, déjà consultable depuis
 * l'écran d'administration) plutôt que d'inventer un nouveau canal.
 */
class VerifierCoherenceStagiairesCommand extends Command
{
    protected $signature = 'stagiaires:verifier-coherence';

    protected $description = 'Détecte les incohérences entre dossiers stagiaire, tableaux de répartition et notifications';

    public function __construct(private readonly AuditLogger $audit)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $total = 0;

        $total += $this->detecterEnInstructionSansLigne();
        $total += $this->detecterBoucleExcessive();
        $total += $this->detecterAffecteSansTableauApprouve();
        $total += $this->detecterNotifieSansFeuVert();
        $total += $this->detecterDoublonApprouve();

        $this->info("{$total} anomalie(s) détectée(s) et journalisée(s).");

        return self::SUCCESS;
    }

    private function rapporter(string $code, Stagiaire $stagiaire, array $contexte = []): void
    {
        $this->audit->enregistrer('coherence.anomalie_detectee', $stagiaire, null, [
            'code' => $code,
            ...$contexte,
        ]);
    }

    /**
     * Un dossier "en instruction" doit toujours avoir une ligne qui le
     * porte — sans elle, il est bloqué dans un statut dont plus aucune
     * action du système ne peut le faire sortir (ni ajouterLigne() ni
     * rendreAvis() ne s'appliquent à un dossier sans ligne).
     */
    private function detecterEnInstructionSansLigne(): int
    {
        $stagiaires = Stagiaire::query()
            ->where('statut', StagiaireStatut::EN_INSTRUCTION)
            ->whereDoesntHave('lignesTableauRepartition')
            ->get();

        foreach ($stagiaires as $stagiaire) {
            $this->rapporter('en_instruction_sans_ligne', $stagiaire);
        }

        return $stagiaires->count();
    }

    /**
     * Le tour de boucle d'un tableau non encore approuvé ne devrait
     * jamais dépasser le seuil d'alerte du circuit courrier — signe d'un
     * dossier qui fait des allers-retours DG/Réception sans jamais se
     * conclure.
     */
    private function detecterBoucleExcessive(): int
    {
        $seuil = (int) config('courrier.circuit.tours_avant_alerte', 5);

        $lignes = TableauRepartitionLigne::query()
            ->whereHas('tableau', fn ($q) => $q
                ->where('statut', '!=', TableauRepartitionStatut::APPROUVE)
                ->whereHas('courrier', fn ($q2) => $q2->where('tour', '>', $seuil)))
            ->with('stagiaire', 'tableau.courrier')
            ->get()
            ->unique('stagiaire_id');

        foreach ($lignes as $ligne) {
            /** @var Stagiaire $stagiaire */
            $stagiaire = $ligne->stagiaire;

            $this->rapporter('boucle_au_dela_du_seuil', $stagiaire, [
                'tableau_repartition_id' => $ligne->tableau_repartition_id,
                'tour' => $ligne->tableau->courrier?->tour,
            ]);
        }

        return $lignes->count();
    }

    /**
     * Un dossier affecté doit toujours pouvoir être rattaché à la ligne,
     * dans un tableau approuvé, qui a produit cette affectation — sans
     * cela, l'affectation n'a pas de justification traçable dans le
     * circuit (voir StagiaireCircuitService::affecter(), appelée
     * uniquement depuis TableauRepartitionCircuitService::rendreAvis()).
     */
    private function detecterAffecteSansTableauApprouve(): int
    {
        $stagiaires = Stagiaire::query()
            ->where('statut', StagiaireStatut::AFFECTE)
            ->whereDoesntHave('lignesTableauRepartition', fn ($q) => $q
                ->where('issue_proposee', IssueProposee::RETENU)
                ->whereHas('tableau', fn ($q2) => $q2->where('statut', TableauRepartitionStatut::APPROUVE)))
            ->get();

        foreach ($stagiaires as $stagiaire) {
            $this->rapporter('affecte_sans_tableau_approuve', $stagiaire);
        }

        return $stagiaires->count();
    }

    /**
     * Aucune notification de diffusion ne devrait exister pour un dossier
     * qui n'est ni affecté ni non retenu — le verrou de diffusion (Lot B)
     * garantit déjà qu'aucun canal ne s'ouvre avant le feu vert ; cette
     * vérification objective que la garantie tient dans la durée.
     */
    private function detecterNotifieSansFeuVert(): int
    {
        $stagiaires = Stagiaire::query()
            ->whereNotIn('statut', [StagiaireStatut::AFFECTE, StagiaireStatut::NON_RETENU, StagiaireStatut::STAGE_EN_COURS, StagiaireStatut::EVALUATION_EN_COURS, StagiaireStatut::CLOTURE])
            ->whereHas('notificationsDiffusion')
            ->get();

        foreach ($stagiaires as $stagiaire) {
            $this->rapporter('notifie_sans_feu_vert', $stagiaire);
        }

        return $stagiaires->count();
    }

    /**
     * Une même demande ne doit jamais figurer dans deux tableaux
     * approuvés à la fois — normalement empêché en amont (voir
     * TableauRepartitionCircuitService::assertAucunDoublonApprouve()),
     * revérifié ici après coup pour couvrir une éventuelle donnée déjà en
     * base avant l'introduction de cette garde, ou une anomalie
     * introduite hors du circuit applicatif normal.
     */
    private function detecterDoublonApprouve(): int
    {
        $doublons = TableauRepartitionLigne::query()
            ->whereHas('tableau', fn ($q) => $q->where('statut', TableauRepartitionStatut::APPROUVE))
            ->selectRaw('stagiaire_id, count(distinct tableau_repartition_id) as nombre_tableaux')
            ->groupBy('stagiaire_id')
            ->havingRaw('count(distinct tableau_repartition_id) > 1')
            ->with('stagiaire')
            ->get();

        foreach ($doublons as $doublon) {
            /** @var Stagiaire $stagiaire */
            $stagiaire = $doublon->stagiaire;

            $this->rapporter('tableau_approuve_avec_demande_deja_traitee_ailleurs', $stagiaire);
        }

        return $doublons->count();
    }
}
