<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\DocumentType;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Services\StagiaireCircuitService;

class GrilleEvaluationTest extends StagiaireTestCase
{
    use RefreshDatabase;

    private function grille(float $facteur): array
    {
        return [
            'aptitudes_professionnelles' => [
                'connaissance_metier' => 10 * $facteur, 'esprit_initiative' => 10 * $facteur, 'sens_responsabilite' => 10 * $facteur,
                'soin_proprete' => 10 * $facteur, 'rendement' => 10 * $facteur, 'justification' => 'Bon travail.',
            ],
            'relations_humaines' => [
                'esprit_equipe' => 10 * $facteur, 'communication' => 10 * $facteur, 'relations_sociales' => 10 * $facteur,
                'justification' => 'Bonne intégration.',
            ],
            'presentation' => [
                'discipline' => 5 * $facteur, 'ponctualite' => 5 * $facteur, 'regularite' => 5 * $facteur, 'tenue' => 5 * $facteur,
                'justification' => 'Correct.',
            ],
        ];
    }

    private function stagiaireEnEvaluation(Direction $direction, bool $periodeOuverte = true): Stagiaire
    {
        return Stagiaire::factory()->create([
            'statut' => StagiaireStatut::EVALUATION_EN_COURS,
            'direction_id' => $direction->id,
            'periode_evaluation_ouverte_at' => $periodeOuverte ? now() : null,
        ]);
    }

    public function test_la_direction_ne_peut_pas_evaluer_tant_que_la_dfp_na_pas_ouvert_la_periode(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $stagiaire = $this->stagiaireEnEvaluation($direction, periodeOuverte: false);

        $this->actingAs($responsable)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/evaluer-direction", ['grille' => $this->grille(0.8)])
            ->assertStatus(403);
    }

    public function test_la_dfp_ouvre_la_periode_puis_la_direction_peut_evaluer(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = $this->stagiaireEnEvaluation($direction, periodeOuverte: false);

        $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/ouvrir-periode-evaluation")
            ->assertOk()
            ->assertJsonPath('data.periode_evaluation_ouverte', true);

        $this->actingAs($responsable)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/evaluer-direction", ['grille' => $this->grille(0.8)])
            ->assertOk()
            ->assertJsonPath('data.evaluation_direction_soumise', true);
    }

    public function test_seule_la_dfp_peut_ouvrir_la_periode_devaluation(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $stagiaire = $this->stagiaireEnEvaluation($direction, periodeOuverte: false);

        $this->actingAs($responsable)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/ouvrir-periode-evaluation")
            ->assertStatus(403);
    }

    public function test_le_total_est_toujours_recalcule_cote_serveur(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $stagiaire = $this->stagiaireEnEvaluation($direction);

        // Un champ hors barème (15 > max 10) doit être rejeté, pas juste ignoré.
        $grilleInvalide = $this->grille(0.8);
        $grilleInvalide['aptitudes_professionnelles']['connaissance_metier'] = 15;

        $this->actingAs($responsable)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/evaluer-direction", ['grille' => $grilleInvalide])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['grille.aptitudes_professionnelles.connaissance_metier']);

        $this->actingAs($responsable)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/evaluer-direction", ['grille' => $this->grille(0.8)])
            ->assertOk();

        $this->assertEquals(80.0, $stagiaire->fresh()->evaluation_direction_total);
    }

    public function test_confidentialite_apres_soumission_par_la_direction(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $stagiaire = $this->stagiaireEnEvaluation($direction);

        $this->actingAs($responsable)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/evaluer-direction", ['grille' => $this->grille(0.8)])
            ->assertOk()
            ->assertJsonMissingPath('data.evaluation');

        // Même en consultant le dossier après coup, la direction ne voit
        // jamais sa propre note en détail, ni celle de la DFP, ni la moyenne.
        $reponse = $this->actingAs($responsable)
            ->getJson("/api/v1/stagiaires/{$stagiaire->id}")
            ->assertOk();

        $reponse->assertJsonMissingPath('data.evaluation');
        $reponse->assertJsonPath('data.evaluation_direction_soumise', true);
        $this->assertStringNotContainsString('"total":80', $reponse->getContent());
    }

    public function test_la_dfp_voit_le_detail_complet_et_la_moyenne_une_fois_les_deux_soumises(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = $this->stagiaireEnEvaluation($direction);

        $this->actingAs($responsable)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/evaluer-direction", ['grille' => $this->grille(0.8)])
            ->assertOk();

        $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/evaluer-dfp", ['grille' => $this->grille(0.6)])
            ->assertOk()
            ->assertJsonPath('data.statut', StagiaireStatut::CLOTURE->value)
            ->assertJsonPath('data.evaluation.direction.total', 80)
            ->assertJsonPath('data.evaluation.dfp.total', 60)
            ->assertJsonPath('data.evaluation.note_finale', 70);
    }

    /**
     * Régression : si la DFP soumet sa grille au même moment que la
     * direction (deux requêtes HTTP quasi simultanées), chacune charge sa
     * propre instance Eloquent du stagiaire avant que l'autre n'ait
     * committé. cloturerSiEvaluationsCompletes() doit donc toujours relire
     * l'état frais depuis la base plutôt que de faire confiance à
     * l'instantané chargé en mémoire — sinon aucune des deux requêtes ne
     * déclenche la clôture et le dossier reste bloqué en evaluation_en_cours.
     */
    public function test_la_cloture_se_declenche_meme_si_lautre_evaluation_a_ete_committee_apres_le_chargement(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = $this->stagiaireEnEvaluation($direction);

        // Instance chargée "avant" — simule la requête Direction, dont
        // l'objet PHP a été hydraté avant que la requête DFP concurrente
        // n'ait sauvegardé sa propre évaluation.
        $instanceChargeeAvant = Stagiaire::find($stagiaire->id);

        // La requête DFP "concurrente" committe entre-temps.
        $stagiaire->evaluation_dfp_grille = $this->grille(0.6);
        $stagiaire->evaluation_dfp_total = 60;
        $stagiaire->evaluation_dfp_at = now();
        $stagiaire->save();

        $service = app(StagiaireCircuitService::class);
        $service->evaluerParDirection($instanceChargeeAvant, $responsable, $this->grille(0.8));

        $stagiaire->refresh();
        $this->assertSame(StagiaireStatut::CLOTURE, $stagiaire->statut);
        $this->assertSame(70.0, $stagiaire->note_finale);
    }

    public function test_la_direction_ne_peut_pas_telecharger_lattestation_meme_apres_cloture(): void
    {
        Storage::fake('local');

        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = $this->stagiaireEnEvaluation($direction);

        $this->actingAs($responsable)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/evaluer-direction", ['grille' => $this->grille(0.8)])
            ->assertOk();
        $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/evaluer-dfp", ['grille' => $this->grille(0.6)])
            ->assertOk();

        $attestation = $stagiaire->fresh()->documents()->where('type', DocumentType::ATTESTATION_STAGE)->firstOrFail();

        $this->actingAs($responsable)
            ->getJson("/api/v1/stagiaires/{$stagiaire->id}/documents/{$attestation->id}/telecharger")
            ->assertStatus(403);

        $this->actingAs($dfp)
            ->get("/api/v1/stagiaires/{$stagiaire->id}/documents/{$attestation->id}/telecharger")
            ->assertOk();
    }

    public function test_le_responsable_dune_autre_direction_ne_peut_pas_evaluer(): void
    {
        // Le Global Scope masque déjà la fiche à une direction qui n'est pas
        // la sienne (404, avant même la policy) — même comportement que le
        // reste des actions direction (voir StagiaireLifecycleTest).
        $direction = Direction::factory()->create();
        $autreDirection = Direction::factory()->create();
        $responsableAutre = User::factory()->responsableDirection($autreDirection)->create();
        $stagiaire = $this->stagiaireEnEvaluation($direction);

        $this->actingAs($responsableAutre)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/evaluer-direction", ['grille' => $this->grille(0.8)])
            ->assertStatus(404);
    }
}
