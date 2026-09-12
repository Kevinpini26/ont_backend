<?php

namespace Modules\Stagiaires\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Modules\Courrier\Enums\MentionImputation;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\IssueProposee;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Services\StagiaireCircuitService;
use Modules\Stagiaires\Services\TableauRepartitionCircuitService;

/**
 * Un stagiaire académique et un stagiaire professionnel à chaque étape du
 * cycle de vie (dossier reçu, en attente d'affectation, affecté, stage en
 * cours avec présences, évaluation en cours, clôturé avec attestation),
 * pour qu'une démonstration à la tutelle montre le parcours complet sans
 * avoir à le rejouer à la main. N'est jamais appelé en dehors de
 * l'environnement local — voir StagiairesDatabaseSeeder::run().
 */
class StagiaireDemoSeeder extends Seeder
{
    private int $compteurCourrier = 0;

    private Direction $direction;

    private TableauRepartitionCircuitService $tableaux;

    private User $reception;

    private User $dg;

    public function run(StagiaireCircuitService $circuit, TableauRepartitionCircuitService $tableaux): void
    {
        $this->direction = Direction::query()->where('code', 'DRHL')->firstOrFail();
        $dfp = User::query()->where('email', 'dfp@ont.cd')->firstOrFail();
        $responsable = User::query()->where('email', 'responsable.drhl@ont.cd')->firstOrFail();
        $this->tableaux = $tableaux;
        $this->reception = User::query()->where('email', 'reception@ont.cd')->firstOrFail();
        $this->dg = User::query()->where('email', 'dg@ont.cd')->firstOrFail();

        foreach (['academique', 'professionnel'] as $typeStage) {
            $this->dossierRecu($typeStage);
            $this->enAttenteAffectation($circuit, $typeStage);
            $this->affecte($circuit, $typeStage, $dfp, $this->direction);
            $this->stageEnCours($circuit, $typeStage, $dfp, $this->direction);
            $this->evaluationEnCours($circuit, $typeStage, $dfp, $this->direction, $responsable);
            $this->cloture($circuit, $typeStage, $dfp, $this->direction, $responsable);
        }
    }

    private function nouveauStagiaire(string $typeStage, string $suffixeNom): Stagiaire
    {
        // Ne jamais laisser Stagiaire::factory() créer son propre Courrier
        // via Courrier::factory() : le compteur statique interne de
        // CourrierFactory::definition() repart de 1 à chaque exécution de
        // ce seeder et entre en collision avec les numéros déjà attribués
        // par CourrierDemoSeeder (numéros générés par le vrai
        // NumeroGenerator). On fournit donc ici un courrier déjà créé, avec
        // un numéro d'accusé de réception préfixé "DEMO" (garanti distinct
        // des deux séquences) et rattaché aux vraies directions de
        // démonstration plutôt qu'à des directions aléatoires générées par
        // la fabrique.
        $courrier = Courrier::factory()->demandeStage()->create([
            'numero_accuse_reception' => sprintf('AR-%d-DEMO%03d', now()->year, ++$this->compteurCourrier),
            'type_stage' => $typeStage,
            'direction_origine_id' => null,
            'direction_destination_id' => $this->direction->id,
            'created_by' => null,
        ]);

        $factory = Stagiaire::factory();
        if ($typeStage === 'professionnel') {
            $factory = $factory->professionnel();
        }

        return $factory->create([
            'courrier_id' => $courrier->id,
            'nom' => "Démo {$typeStage} — {$suffixeNom}",
        ]);
    }

    private function dossierRecu(string $typeStage): void
    {
        // Statut par défaut de la fabrique : rien à faire progresser.
        $this->nouveauStagiaire($typeStage, 'dossier reçu');
    }

    private function enAttenteAffectation(StagiaireCircuitService $circuit, string $typeStage): void
    {
        $stagiaire = $this->nouveauStagiaire($typeStage, "en attente d'affectation");
        $circuit->examinerDossier($stagiaire);
    }

    private function affecte(StagiaireCircuitService $circuit, string $typeStage, User $dfp, Direction $direction): void
    {
        $stagiaire = $this->nouveauStagiaire($typeStage, 'affecté');
        $circuit->examinerDossier($stagiaire);
        $this->affecterViaTableau($stagiaire, $dfp, $direction);
    }

    private function stageEnCours(StagiaireCircuitService $circuit, string $typeStage, User $dfp, Direction $direction): void
    {
        $stagiaire = $this->nouveauStagiaire($typeStage, 'stage en cours');
        $circuit->examinerDossier($stagiaire);
        $this->affecterViaTableau($stagiaire, $dfp, $direction);

        $debut = Carbon::now()->subWeeks(2);
        $circuit->validerArrivee($stagiaire, $debut, $debut->copy()->addWeeks(8));

        $this->enregistrerPresences($circuit, $stagiaire, $dfp, $debut, Carbon::now());
    }

    private function evaluationEnCours(StagiaireCircuitService $circuit, string $typeStage, User $dfp, Direction $direction, User $responsable): void
    {
        $stagiaire = $this->nouveauStagiaire($typeStage, 'évaluation en cours');
        $circuit->examinerDossier($stagiaire);
        $this->affecterViaTableau($stagiaire, $dfp, $direction);

        $debut = Carbon::now()->subMonths(3);
        $fin = $debut->copy()->addWeeks(8);
        $circuit->validerArrivee($stagiaire, $debut, $fin);
        $this->enregistrerPresences($circuit, $stagiaire, $dfp, $debut, $fin);

        $circuit->terminerStage($stagiaire);
        $circuit->ouvrirPeriodeEvaluation($stagiaire, $dfp);

        // Seule la direction a évalué : la DFP n'a pas encore noté, le
        // dossier reste donc bien en évaluation, pas encore clôturé.
        $circuit->evaluerParDirection($stagiaire, $responsable, $this->grille($typeStage, 0.8));
    }

    private function cloture(StagiaireCircuitService $circuit, string $typeStage, User $dfp, Direction $direction, User $responsable): void
    {
        $stagiaire = $this->nouveauStagiaire($typeStage, 'clôturé');
        $circuit->examinerDossier($stagiaire);
        $this->affecterViaTableau($stagiaire, $dfp, $direction);

        $debut = Carbon::now()->subMonths(4);
        $fin = $debut->copy()->addWeeks(8);
        $circuit->validerArrivee($stagiaire, $debut, $fin);
        $this->enregistrerPresences($circuit, $stagiaire, $dfp, $debut, $fin);

        $circuit->terminerStage($stagiaire);
        $circuit->ouvrirPeriodeEvaluation($stagiaire, $dfp);
        $circuit->evaluerParDirection($stagiaire, $responsable, $this->grille($typeStage, 1.0));
        // La seconde évaluation déclenche la clôture automatique et la
        // génération de l'attestation (avec son sceau de réussite).
        $circuit->evaluerParDfp($stagiaire, $dfp, $this->grille($typeStage, 1.0));
    }

    /**
     * Lot 5/A : l'affectation réelle n'est plus qu'un effet de
     * l'approbation d'un tableau de répartition — reproduit tout le
     * trajet (imputation à la DFP, tableau, ligne, soumission,
     * présentation à la DG, approbation) pour chaque stagiaire de
     * démonstration qui doit apparaître "affecté".
     */
    private function affecterViaTableau(Stagiaire $stagiaire, User $dfp, Direction $direction): void
    {
        /** @var Courrier $courrier */
        $courrier = $stagiaire->courrier()->firstOrFail();
        $courrier->imputations()->create([
            'direction_id' => Direction::query()->where('code', config('stagiaires.direction_dfp_code'))->firstOrFail()->id,
            'mention' => MentionImputation::POUR_ATTRIBUTION,
            'est_principale' => true,
            'imputee_par_id' => $this->dg->id,
        ]);

        $tableau = $this->tableaux->creer($dfp, now()->subMonth()->toDateString(), now()->addMonth()->toDateString());
        $this->tableaux->ajouterLigne(
            $tableau, $stagiaire, $dfp, $direction->id,
            now()->toDateString(), now()->addMonths(3)->toDateString(),
            'Encadrant de démonstration',
            IssueProposee::RETENU, null, null,
        );
        $this->tableaux->soumettre($tableau, $dfp);
        $this->tableaux->representerDg($tableau, $this->reception);
        $this->tableaux->rendreAvis($tableau, $this->dg, true, null);

        // L'approbation affecte $stagiaire via une tout autre instance
        // (chargée depuis la ligne, à l'intérieur du service) : sans ce
        // refresh(), l'appelant continue de voir "en instruction" en
        // mémoire et toute étape suivante (validerArrivee()...) échoue.
        $stagiaire->refresh();
    }

    private function enregistrerPresences(StagiaireCircuitService $circuit, Stagiaire $stagiaire, User $saisiPar, Carbon $debut, Carbon $fin): void
    {
        $jour = $debut->copy();
        $joursSaisis = 0;

        while ($jour->lte($fin) && $jour->lte(Carbon::now()) && $joursSaisis < 10) {
            if (! $jour->isWeekend()) {
                $circuit->enregistrerPresence($stagiaire, $saisiPar, $jour->copy(), '08:00', '16:00');
                $joursSaisis++;
            }
            $jour->addDay();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function grille(string $typeStage, float $facteur): array
    {
        if ($typeStage === 'professionnel') {
            return [
                'aspects_intellectuels' => [
                    'connaissance_metier' => 10 * $facteur,
                    'esprit_initiative_responsabilite' => 10 * $facteur,
                    'capacite_ecoute_communication' => 10 * $facteur,
                ],
                'aspects_humains' => [
                    'assiduite_discipline' => 10 * $facteur,
                    'relation_interpersonnelle' => 10 * $facteur,
                    'ponctualite_regularite' => 10 * $facteur,
                    'presentation_contacts' => 10 * $facteur,
                ],
                'aspects_professionnels' => [
                    'efficacite_rendement' => 10 * $facteur,
                    'capacite_innovation' => 10 * $facteur,
                    'maitrise_langue' => 10 * $facteur,
                ],
            ];
        }

        return [
            'aptitudes_professionnelles' => [
                'connaissance_metier' => 10 * $facteur, 'esprit_initiative' => 10 * $facteur, 'sens_responsabilite' => 10 * $facteur,
                'soin_proprete' => 10 * $facteur, 'rendement' => 10 * $facteur, 'justification' => 'RAS — démonstration.',
            ],
            'relations_humaines' => [
                'esprit_equipe' => 10 * $facteur, 'communication' => 10 * $facteur, 'relations_sociales' => 10 * $facteur,
                'justification' => 'RAS — démonstration.',
            ],
            'presentation' => [
                'discipline' => 5 * $facteur, 'ponctualite' => 5 * $facteur, 'regularite' => 5 * $facteur, 'tenue' => 5 * $facteur,
                'justification' => 'RAS — démonstration.',
            ],
        ];
    }
}
