<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\DocumentType;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;

class NoteAffectationTest extends StagiaireTestCase
{
    use RefreshDatabase;

    public function test_laffectation_genere_une_note_daffectation(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $direction = Direction::factory()->create(['actif' => true]);
        $candidat = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);

        $this->affecterViaTableau($candidat, $direction, $dfp)->assertOk();

        $this->assertDatabaseHas('stagiaire_documents', [
            'stagiaire_id' => $candidat->id,
            'type' => DocumentType::NOTE_AFFECTATION->value,
        ]);
    }

    public function test_la_direction_daccueil_peut_telecharger_la_note_daffectation(): void
    {
        $dfp = User::factory()->agentDfp()->create();
        $direction = Direction::factory()->create(['actif' => true]);
        $candidat = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);

        $this->affecterViaTableau($candidat, $direction, $dfp)->assertOk();

        $document = $candidat->fresh()->documents()->where('type', DocumentType::NOTE_AFFECTATION->value)->firstOrFail();
        $responsable = User::factory()->responsableDirection($direction)->create();

        $this->actingAs($responsable)
            ->getJson("/api/v1/stagiaires/{$candidat->id}/documents/{$document->id}/telecharger")
            ->assertOk();
    }
}
