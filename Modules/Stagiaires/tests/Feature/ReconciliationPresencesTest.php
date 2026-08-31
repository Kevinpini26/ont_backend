<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Models\Stagiaire;

/**
 * Mode dégradé hors ligne : une direction sans connexion continue (ex.
 * représentation provinciale) note l'assiduité localement puis transmet le
 * lot une fois la connexion rétablie — voir
 * StagiaireCircuitService::reconcilierPresences().
 */
class ReconciliationPresencesTest extends StagiaireTestCase
{
    use RefreshDatabase;

    public function test_un_lot_de_presences_sans_conflit_est_applique_integralement(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->create();

        $reponse = $this->actingAs($dfp)->postJson("/api/v1/stagiaires/{$stagiaire->id}/presences/reconciliation", [
            'entrees' => [
                ['date' => '2026-03-02', 'heure_arrivee' => '08:00', 'heure_depart' => '16:00'],
                ['date' => '2026-03-03', 'heure_arrivee' => '08:15', 'heure_depart' => '16:00'],
            ],
        ])->assertOk();

        $reponse->assertJsonPath('data.0.statut', 'applique');
        $reponse->assertJsonPath('data.1.statut', 'applique');
        $this->assertDatabaseHas('stagiaire_presences', ['stagiaire_id' => $stagiaire->id, 'date' => '2026-03-02', 'heure_arrivee' => '08:00:00']);
    }

    public function test_une_entree_en_conflit_avec_une_valeur_deja_saisie_nest_pas_ecrasee(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->create();

        // Déjà saisi en ligne entre-temps, avec une heure différente.
        $this->actingAs($dfp)->postJson("/api/v1/stagiaires/{$stagiaire->id}/presences", [
            'date' => '2026-03-02', 'heure_arrivee' => '09:00',
        ])->assertCreated();

        $reponse = $this->actingAs($dfp)->postJson("/api/v1/stagiaires/{$stagiaire->id}/presences/reconciliation", [
            'entrees' => [
                ['date' => '2026-03-02', 'heure_arrivee' => '08:00', 'heure_depart' => null],
            ],
        ])->assertOk();

        $reponse->assertJsonPath('data.0.statut', 'conflit');
        $reponse->assertJsonPath('data.0.champs_conflit', ['heure_arrivee']);
        // La valeur déjà en base n'a pas été écrasée par l'entrée hors ligne.
        $this->assertDatabaseHas('stagiaire_presences', ['stagiaire_id' => $stagiaire->id, 'date' => '2026-03-02', 'heure_arrivee' => '09:00:00']);
    }

    public function test_forcer_ecrase_malgre_le_conflit(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $stagiaire = Stagiaire::factory()->create();

        $this->actingAs($dfp)->postJson("/api/v1/stagiaires/{$stagiaire->id}/presences", [
            'date' => '2026-03-02', 'heure_arrivee' => '09:00',
        ])->assertCreated();

        $reponse = $this->actingAs($dfp)->postJson("/api/v1/stagiaires/{$stagiaire->id}/presences/reconciliation", [
            'entrees' => [
                ['date' => '2026-03-02', 'heure_arrivee' => '08:00', 'heure_depart' => null],
            ],
            'forcer' => true,
        ])->assertOk();

        $reponse->assertJsonPath('data.0.statut', 'applique');
        $this->assertDatabaseHas('stagiaire_presences', ['stagiaire_id' => $stagiaire->id, 'date' => '2026-03-02', 'heure_arrivee' => '08:00:00']);
    }

    public function test_une_direction_daccueil_ne_peut_pas_reconcilier_les_presences(): void
    {
        $direction = Direction::factory()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        // Visible via son propre direction_id, pour isoler le rejet policy
        // (gererPresence réservé à la DFP) d'une invisibilité de scope.
        $stagiaire = Stagiaire::factory()->create(['direction_id' => $direction->id]);

        $this->actingAs($responsable)->postJson("/api/v1/stagiaires/{$stagiaire->id}/presences/reconciliation", [
            'entrees' => [['date' => '2026-03-02', 'heure_arrivee' => '08:00']],
        ])->assertStatus(403);
    }
}
