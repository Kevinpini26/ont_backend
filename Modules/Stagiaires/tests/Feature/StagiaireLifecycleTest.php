<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;

class StagiaireLifecycleTest extends StagiaireTestCase
{
    use RefreshDatabase;

    private function dfp(): User
    {
        return User::factory()->agentDfp()->create();
    }

    private function grille(float $facteur): array
    {
        return [
            'aptitudes_professionnelles' => [
                'connaissance_metier' => 10 * $facteur, 'esprit_initiative' => 10 * $facteur, 'sens_responsabilite' => 10 * $facteur,
                'soin_proprete' => 10 * $facteur, 'rendement' => 10 * $facteur, 'justification' => 'RAS',
            ],
            'relations_humaines' => [
                'esprit_equipe' => 10 * $facteur, 'communication' => 10 * $facteur, 'relations_sociales' => 10 * $facteur,
                'justification' => 'RAS',
            ],
            'presentation' => [
                'discipline' => 5 * $facteur, 'ponctualite' => 5 * $facteur, 'regularite' => 5 * $facteur, 'tenue' => 5 * $facteur,
                'justification' => 'RAS',
            ],
        ];
    }

    public function test_le_cycle_de_vie_complet_jusqua_la_cloture_avec_moyenne_des_evaluations(): void
    {
        $direction = Direction::factory()->create(['actif' => true]);
        $dfp = $this->dfp();
        $responsable = User::factory()->responsableDirection($direction)->create();

        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::DOSSIER_RECU]);

        $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/examiner-dossier")
            ->assertOk()
            ->assertJsonPath('data.statut', StagiaireStatut::EN_ATTENTE_AFFECTATION->value);

        $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/affecter", ['direction_id' => $direction->id])
            ->assertOk()
            ->assertJsonPath('data.statut', StagiaireStatut::AFFECTE->value);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $responsable->id,
            'notifiable_type' => User::class,
        ]);

        $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/valider-arrivee", [
                'date_debut_stage' => now()->addDays(5)->toDateString(),
                'date_fin_stage' => now()->addDays(65)->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.statut', StagiaireStatut::STAGE_EN_COURS->value);

        $this->actingAs($responsable)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/terminer-stage")
            ->assertOk()
            ->assertJsonPath('data.statut', StagiaireStatut::EVALUATION_EN_COURS->value);

        // La DFP doit explicitement ouvrir la période avant que la direction
        // n'ait accès à son formulaire d'évaluation.
        $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/ouvrir-periode-evaluation")
            ->assertOk()
            ->assertJsonPath('data.periode_evaluation_ouverte', true);

        // Première évaluation (direction) : le dossier reste en évaluation
        // tant que la DFP n'a pas noté à son tour.
        $this->actingAs($responsable)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/evaluer-direction", ['grille' => $this->grille(0.8)])
            ->assertOk()
            ->assertJsonPath('data.statut', StagiaireStatut::EVALUATION_EN_COURS->value);

        $response = $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/evaluer-dfp", ['grille' => $this->grille(0.7)])
            ->assertOk()
            ->assertJsonPath('data.statut', StagiaireStatut::CLOTURE->value);

        $this->assertEquals(75.0, $response->json('data.evaluation.note_finale'));

        $stagiaire->refresh();
        $this->assertNotNull($stagiaire->cloture_at);
        $this->assertNotNull($stagiaire->numero_attestation);
        $this->assertMatchesRegularExpression('/^ATT-\d{4}-\d{6}$/', $stagiaire->numero_attestation);
        $this->assertDatabaseHas('stagiaire_documents', [
            'stagiaire_id' => $stagiaire->id,
            'type' => 'attestation_stage',
        ]);
    }

    /**
     * Trouvé en peuplant des données de démonstration réalistes
     * (StagiaireDemoSeeder) : une double évaluation parfaite (100,0 par la
     * direction ET par la DFP) donne une moyenne de 100,0, que la colonne
     * note_finale (à l'origine decimal(4,2), plafonnée à 99,99) ne pouvait
     * pas stocker — voir la migration widen_note_finale_precision.
     */
    public function test_une_double_evaluation_parfaite_ne_depasse_pas_la_precision_de_la_note_finale(): void
    {
        $direction = Direction::factory()->create(['actif' => true]);
        $dfp = $this->dfp();
        $responsable = User::factory()->responsableDirection($direction)->create();

        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::STAGE_EN_COURS, 'direction_id' => $direction->id]);

        $this->actingAs($responsable)->postJson("/api/v1/stagiaires/{$stagiaire->id}/terminer-stage")->assertOk();
        $this->actingAs($dfp)->postJson("/api/v1/stagiaires/{$stagiaire->id}/ouvrir-periode-evaluation")->assertOk();
        $this->actingAs($responsable)->postJson("/api/v1/stagiaires/{$stagiaire->id}/evaluer-direction", ['grille' => $this->grille(1.0)])->assertOk();

        $response = $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/evaluer-dfp", ['grille' => $this->grille(1.0)])
            ->assertOk()
            ->assertJsonPath('data.statut', StagiaireStatut::CLOTURE->value);

        $this->assertEquals(100.0, $response->json('data.evaluation.note_finale'));
    }

    public function test_les_numeros_dattestation_sont_uniques_et_sequentiels(): void
    {
        $direction = Direction::factory()->create(['actif' => true]);
        $dfp = $this->dfp();

        $numeros = [];

        foreach (range(1, 2) as $_) {
            $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::STAGE_EN_COURS, 'direction_id' => $direction->id]);
            $responsable = User::factory()->responsableDirection($direction)->create();

            $this->actingAs($responsable)->postJson("/api/v1/stagiaires/{$stagiaire->id}/terminer-stage")->assertOk();
            $this->actingAs($dfp)->postJson("/api/v1/stagiaires/{$stagiaire->id}/ouvrir-periode-evaluation")->assertOk();
            $this->actingAs($responsable)->postJson("/api/v1/stagiaires/{$stagiaire->id}/evaluer-direction", ['grille' => $this->grille(0.75)])->assertOk();
            $this->actingAs($dfp)->postJson("/api/v1/stagiaires/{$stagiaire->id}/evaluer-dfp", ['grille' => $this->grille(0.75)])->assertOk();

            $numeros[] = $stagiaire->refresh()->numero_attestation;
        }

        $this->assertCount(2, array_unique($numeros));
    }

    public function test_seul_le_dfp_peut_affecter_un_stagiaire(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();

        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);

        // Un dossier pas encore affecté n'a pas de direction_id : le Global
        // Scope le rend invisible à tout responsable de direction (404),
        // seuls DFP/admin/postes centraux le voient avant affectation.
        $this->actingAs($responsable)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/affecter", ['direction_id' => $direction->id])
            ->assertStatus(404);
    }

    public function test_seule_la_direction_daccueil_correspondante_peut_evaluer_le_travail(): void
    {
        $direction = Direction::factory()->create();
        $autreDirection = Direction::factory()->create();
        $autreResponsable = User::factory()->responsableDirection($autreDirection)->create();

        $stagiaire = Stagiaire::factory()->create([
            'statut' => StagiaireStatut::EVALUATION_EN_COURS,
            'direction_id' => $direction->id,
        ]);

        // Le Global Scope masque déjà la fiche à une direction qui n'est
        // pas la sienne (404) avant même la vérification de la policy.
        $this->actingAs($autreResponsable)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/evaluer-direction", ['note' => 18])
            ->assertStatus(404);
    }

    public function test_le_dfp_ne_peut_pas_evaluer_a_la_place_de_la_direction_daccueil(): void
    {
        $direction = Direction::factory()->create();
        $dfp = $this->dfp();

        // La DFP contourne le Global Scope (elle voit la fiche) mais n'est
        // pas autorisée à endosser le rôle d'évaluation de la direction
        // d'accueil : c'est bien une policy 403, pas un 404 de scope.
        $stagiaire = Stagiaire::factory()->create([
            'statut' => StagiaireStatut::EVALUATION_EN_COURS,
            'direction_id' => $direction->id,
        ]);

        $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/evaluer-direction", ['note' => 18])
            ->assertStatus(403);
    }

    public function test_laffectation_est_refusee_pour_une_direction_inactive(): void
    {
        $direction = Direction::factory()->create(['actif' => false]);
        $dfp = $this->dfp();

        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);

        $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/affecter", ['direction_id' => $direction->id])
            ->assertStatus(422);
    }
}
