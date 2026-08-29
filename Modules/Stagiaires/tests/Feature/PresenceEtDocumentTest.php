<?php

namespace Modules\Stagiaires\Tests\Feature;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;

class PresenceEtDocumentTest extends StagiaireTestCase
{
    use RefreshDatabase;

    private function jourOuvrePasse(): Carbon
    {
        $jour = now()->subDay();
        while ($jour->isWeekend()) {
            $jour->subDay();
        }

        return $jour->startOfDay();
    }

    public function test_la_dfp_peut_enregistrer_une_arrivee_et_un_depart_separement(): void
    {
        $agentDfp = User::factory()->agentDfp()->create();

        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::STAGE_EN_COURS]);
        $jour = $this->jourOuvrePasse()->toDateString();

        $this->actingAs($agentDfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/presences", [
                'date' => $jour,
                'heure_arrivee' => '08:25',
            ])
            ->assertCreated()
            ->assertJsonPath('data.incomplete', true);

        $this->assertDatabaseHas('stagiaire_presences', ['stagiaire_id' => $stagiaire->id, 'date' => $jour, 'heure_depart' => null]);

        // Deuxième appel, même jour : complète le départ sans écraser l'arrivée déjà saisie (upsert).
        $this->actingAs($agentDfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/presences", [
                'date' => $jour,
                'heure_depart' => '15:40',
            ])
            ->assertCreated()
            ->assertJsonPath('data.incomplete', false);

        $this->assertDatabaseCount('stagiaire_presences', 1);
        $this->assertDatabaseHas('stagiaire_presences', [
            'stagiaire_id' => $stagiaire->id,
            'date' => $jour,
            'heure_arrivee' => '08:25:00',
            'heure_depart' => '15:40:00',
        ]);
    }

    /**
     * "Double-clic" ou deux onglets ouverts sur le même jour : deux
     * pointages d'arrivée pour la même date ne doivent jamais créer deux
     * lignes (contrainte unique stagiaire_id+date), juste faire gagner la
     * dernière écriture — voir StagiaireCircuitService::enregistrerPresence(),
     * verrou de ligne + repli sur UniqueConstraintViolationException.
     */
    public function test_deux_pointages_darrivee_le_meme_jour_ne_creent_quune_seule_ligne(): void
    {
        $agentDfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::STAGE_EN_COURS]);
        $jour = $this->jourOuvrePasse()->toDateString();

        $this->actingAs($agentDfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/presences", ['date' => $jour, 'heure_arrivee' => '08:25'])
            ->assertCreated();

        $this->actingAs($agentDfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/presences", ['date' => $jour, 'heure_arrivee' => '08:40'])
            ->assertCreated();

        $this->assertDatabaseCount('stagiaire_presences', 1);
        $this->assertDatabaseHas('stagiaire_presences', [
            'stagiaire_id' => $stagiaire->id,
            'date' => $jour,
            'heure_arrivee' => '08:40:00',
        ]);
    }

    public function test_le_pointage_refuse_un_jour_de_week_end(): void
    {
        $agentDfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::STAGE_EN_COURS]);

        $dimanche = now()->next(CarbonInterface::SUNDAY)->toDateString();

        $this->actingAs($agentDfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/presences", [
                'date' => $dimanche,
                'heure_arrivee' => '08:30',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }

    public function test_la_direction_daccueil_ne_peut_plus_enregistrer_de_presence(): void
    {
        // La gestion des présences est centralisée à la DFP : même le
        // responsable de la direction d'accueil du stagiaire concerné n'y a
        // plus accès (avant cette correction, c'était l'inverse).
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();

        $stagiaire = Stagiaire::factory()->create([
            'statut' => StagiaireStatut::STAGE_EN_COURS,
            'direction_id' => $direction->id,
        ]);

        $this->actingAs($responsable)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/presences", [
                'date' => $this->jourOuvrePasse()->toDateString(),
                'heure_arrivee' => '08:30',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('stagiaire_presences', ['stagiaire_id' => $stagiaire->id]);
    }

    public function test_le_pointage_exige_au_moins_une_heure(): void
    {
        $agentDfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::STAGE_EN_COURS]);

        $this->actingAs($agentDfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/presences", [
                'date' => $this->jourOuvrePasse()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['heure_arrivee']);
    }

    public function test_la_dfp_peut_decocher_une_presence_deja_saisie(): void
    {
        $agentDfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::STAGE_EN_COURS]);
        $jour = $this->jourOuvrePasse()->toDateString();

        $this->actingAs($agentDfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/presences", ['date' => $jour, 'heure_arrivee' => '08:30'])
            ->assertCreated();

        $this->actingAs($agentDfp)
            ->deleteJson("/api/v1/stagiaires/{$stagiaire->id}/presences/{$jour}")
            ->assertNoContent();

        $this->assertDatabaseMissing('stagiaire_presences', ['stagiaire_id' => $stagiaire->id, 'date' => $jour]);
    }

    public function test_decocher_une_date_jamais_saisie_ne_produit_aucune_erreur(): void
    {
        $agentDfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::STAGE_EN_COURS]);
        $jour = $this->jourOuvrePasse()->toDateString();

        $this->actingAs($agentDfp)
            ->deleteJson("/api/v1/stagiaires/{$stagiaire->id}/presences/{$jour}")
            ->assertNoContent();
    }

    public function test_la_direction_daccueil_ne_peut_pas_decocher_une_presence(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $stagiaire = Stagiaire::factory()->create(['direction_id' => $direction->id, 'statut' => StagiaireStatut::STAGE_EN_COURS]);
        $jour = $this->jourOuvrePasse()->toDateString();

        $this->actingAs($responsable)
            ->deleteJson("/api/v1/stagiaires/{$stagiaire->id}/presences/{$jour}")
            ->assertForbidden();
    }

    public function test_la_direction_daccueil_ne_peut_pas_consulter_les_presences(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();

        $stagiaire = Stagiaire::factory()->create(['direction_id' => $direction->id]);

        $this->actingAs($responsable)
            ->getJson("/api/v1/stagiaires/{$stagiaire->id}/presences")
            ->assertForbidden();
    }

    public function test_upload_dun_document_du_dossier(): void
    {
        Storage::fake('local');

        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();

        $stagiaire = Stagiaire::factory()->create(['direction_id' => $direction->id]);

        $fichier = UploadedFile::fake()->create('lettre.pdf', 100, 'application/pdf');

        $this->actingAs($responsable)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/documents", [
                'type' => 'lettre_stage_universite',
                'fichier' => $fichier,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('stagiaire_documents', [
            'stagiaire_id' => $stagiaire->id,
            'type' => 'lettre_stage_universite',
        ]);
    }
}
