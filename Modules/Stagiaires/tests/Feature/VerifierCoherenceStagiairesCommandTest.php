<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\CourrierType;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Models\AuditLog;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\IssueProposee;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Enums\TableauRepartitionStatut;
use Modules\Stagiaires\Models\NotificationDiffusion;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Models\TableauRepartition;
use Modules\Stagiaires\Models\TableauRepartitionLigne;

/**
 * Lot D, point 4 : la commande de cohérence nocturne ne corrige jamais
 * rien — elle rapporte chaque anomalie à l'administrateur via le journal
 * d'audit (voir VerifierCoherenceStagiairesCommand).
 */
class VerifierCoherenceStagiairesCommandTest extends StagiaireTestCase
{
    use RefreshDatabase;

    private function anomaliesDeCode(string $code): Collection
    {
        return AuditLog::query()
            ->where('action', 'coherence.anomalie_detectee')
            ->get()
            ->filter(fn ($log) => ($log->meta['code'] ?? null) === $code);
    }

    public function test_detecte_un_dossier_en_instruction_sans_aucune_ligne(): void
    {
        $orphelin = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_INSTRUCTION]);
        Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);

        $this->artisan('stagiaires:verifier-coherence');

        $anomalies = $this->anomaliesDeCode('en_instruction_sans_ligne');
        $this->assertCount(1, $anomalies);
        $this->assertSame($orphelin->id, $anomalies->first()->auditable_id);
    }

    public function test_detecte_un_dossier_affecte_sans_tableau_approuve(): void
    {
        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::AFFECTE]);

        $this->artisan('stagiaires:verifier-coherence');

        $anomalies = $this->anomaliesDeCode('affecte_sans_tableau_approuve');
        $this->assertCount(1, $anomalies);
        $this->assertSame($stagiaire->id, $anomalies->first()->auditable_id);
    }

    public function test_ne_signale_pas_un_dossier_affecte_via_un_vrai_tableau_approuve(): void
    {
        $directionDfp = $this->directionDfp();
        $direction = Direction::factory()->create();
        $dfp = User::factory()->agentDfp()->create();

        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);
        $this->affecterViaTableau($stagiaire, $direction, $dfp);

        $this->artisan('stagiaires:verifier-coherence');

        $this->assertCount(0, $this->anomaliesDeCode('affecte_sans_tableau_approuve'));
    }

    public function test_detecte_un_stagiaire_notifie_sans_feu_vert(): void
    {
        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);
        NotificationDiffusion::query()->create([
            'stagiaire_id' => $stagiaire->id,
            'type' => 'retenu',
            'canal' => 'email',
            'destinataire' => 'x@example.com',
            'contenu' => 'x',
            'envoye_at' => now(),
        ]);

        $this->artisan('stagiaires:verifier-coherence');

        $anomalies = $this->anomaliesDeCode('notifie_sans_feu_vert');
        $this->assertCount(1, $anomalies);
        $this->assertSame($stagiaire->id, $anomalies->first()->auditable_id);
    }

    public function test_detecte_un_doublon_de_tableau_approuve(): void
    {
        $direction = Direction::factory()->create();
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::AFFECTE]);

        // Deux tableaux "approuvés" portant la même demande — situation
        // que le circuit normal empêche (assertAucunDoublonApprouve), donc
        // simulée directement en base pour ce test de la commande.
        foreach (range(1, 2) as $_) {
            $tableau = TableauRepartition::query()->create([
                'direction_id' => $direction->id,
                'redacteur_id' => $dfp->id,
                'periode_debut' => now()->toDateString(),
                'periode_fin' => now()->addMonth()->toDateString(),
                'statut' => TableauRepartitionStatut::APPROUVE,
            ]);
            TableauRepartitionLigne::query()->create([
                'tableau_repartition_id' => $tableau->id,
                'stagiaire_id' => $stagiaire->id,
                'direction_accueil_proposee_id' => $direction->id,
                'date_debut_proposee' => now()->addMonth()->toDateString(),
                'date_fin_proposee' => now()->addMonths(3)->toDateString(),
                'encadrant_pressenti' => 'x',
                'issue_proposee' => IssueProposee::RETENU,
            ]);
        }

        $this->artisan('stagiaires:verifier-coherence');

        $anomalies = $this->anomaliesDeCode('tableau_approuve_avec_demande_deja_traitee_ailleurs');
        $this->assertCount(1, $anomalies);
        $this->assertSame($stagiaire->id, $anomalies->first()->auditable_id);
    }

    public function test_detecte_une_boucle_au_dela_du_seuil_sur_un_tableau_non_approuve(): void
    {
        $direction = Direction::factory()->create();
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_INSTRUCTION]);

        $courrier = Courrier::factory()->create([
            'type' => CourrierType::TABLEAU_REPARTITION,
            'statut' => CourrierStatut::TABLEAU_EN_ATTENTE_AVIS_DG,
            'tour' => 6,
        ]);
        $tableau = TableauRepartition::query()->create([
            'courrier_id' => $courrier->id,
            'direction_id' => $direction->id,
            'redacteur_id' => $dfp->id,
            'periode_debut' => now()->toDateString(),
            'periode_fin' => now()->addMonth()->toDateString(),
            'statut' => TableauRepartitionStatut::EN_ATTENTE_AVIS_DG,
        ]);
        TableauRepartitionLigne::query()->create([
            'tableau_repartition_id' => $tableau->id,
            'stagiaire_id' => $stagiaire->id,
            'direction_accueil_proposee_id' => $direction->id,
            'date_debut_proposee' => now()->addMonth()->toDateString(),
            'date_fin_proposee' => now()->addMonths(3)->toDateString(),
            'encadrant_pressenti' => 'x',
            'issue_proposee' => IssueProposee::RETENU,
        ]);

        $this->artisan('stagiaires:verifier-coherence');

        $anomalies = $this->anomaliesDeCode('boucle_au_dela_du_seuil');
        $this->assertCount(1, $anomalies);
        $this->assertSame($stagiaire->id, $anomalies->first()->auditable_id);
        $this->assertSame(6, $anomalies->first()->meta['tour']);
    }

    public function test_ne_signale_rien_pour_une_base_coherente(): void
    {
        $directionDfp = $this->directionDfp();
        $direction = Direction::factory()->create();
        $dfp = User::factory()->agentDfp()->create();

        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);
        $this->affecterViaTableau($stagiaire, $direction, $dfp);

        $this->artisan('stagiaires:verifier-coherence')->assertSuccessful();

        $this->assertSame(0, AuditLog::query()->where('action', 'coherence.anomalie_detectee')->count());
    }
}
