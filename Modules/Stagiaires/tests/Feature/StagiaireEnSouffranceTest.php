<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;

/**
 * Lot C, point 5 : délai indicatif par étape, ancienneté calculée depuis
 * l'entrée dans l'étape courante (voir Stagiaire::statut_change_at), trois
 * seuils visuels — jamais bloquant.
 */
class StagiaireEnSouffranceTest extends StagiaireTestCase
{
    use RefreshDatabase;

    public function test_un_dossier_ancien_remonte_en_souffrance_avec_le_bon_niveau(): void
    {
        // Lot 6 : les délais se comptent en heures ouvrées ; date fixe un mercredi 15 h (Kinshasa) pour que le test ne dépende pas du jour où il tourne.
        $this->travelTo(Carbon::parse('2026-11-11 15:00', 'Africa/Kinshasa'));
        config(['stagiaires.delais_indicatifs_heures' => ['dossier_recu' => 7]]);

        $dfp = User::factory()->agentDfp()->create();

        $ancien = Stagiaire::factory()->create([
            'statut' => StagiaireStatut::DOSSIER_RECU,
            'statut_change_at' => Carbon::parse('2026-11-09 08:30', 'Africa/Kinshasa'), // 20 h 30 ouvrées : niveau 2
        ]);
        $recent = Stagiaire::factory()->create([
            'statut' => StagiaireStatut::DOSSIER_RECU,
            'statut_change_at' => now()->subHours(10),
        ]);

        $reponse = $this->actingAs($dfp)->getJson('/api/v1/stagiaires/en-souffrance')->assertOk();

        $lignes = collect($reponse->json('data'));
        $this->assertTrue($lignes->contains(fn ($l) => $l['stagiaire']['id'] === $ancien->id));
        $this->assertFalse($lignes->contains(fn ($l) => $l['stagiaire']['id'] === $recent->id));

        $ligneAncien = $lignes->firstWhere('stagiaire.id', $ancien->id);
        // 100h >= 48*2 mais < 48*3 : niveau 2.
        $this->assertSame(2, $ligneAncien['niveau']);
    }

    public function test_un_dossier_clos_najamais_de_niveau_de_souffrance(): void
    {
        $dfp = User::factory()->agentDfp()->create();

        Stagiaire::factory()->create([
            'statut' => StagiaireStatut::CLOTURE,
            'statut_change_at' => now()->subYears(2),
        ]);

        $reponse = $this->actingAs($dfp)->getJson('/api/v1/stagiaires/en-souffrance')->assertOk();

        $this->assertCount(0, $reponse->json('data'));
    }

    public function test_une_direction_ne_voit_en_souffrance_que_ses_propres_dossiers_en_evaluation(): void
    {
        // Lot 6 : les délais se comptent en heures ouvrées ; date fixe un mercredi 15 h (Kinshasa) pour que le test ne dépende pas du jour où il tourne.
        $this->travelTo(Carbon::parse('2026-11-11 15:00', 'Africa/Kinshasa'));
        config(['stagiaires.delais_indicatifs_heures' => ['evaluation_en_cours' => 7]]);

        $responsable = User::factory()->responsableDirection()->create();

        $lui = Stagiaire::factory()->create([
            'statut' => StagiaireStatut::EVALUATION_EN_COURS,
            'statut_change_at' => now()->subHours(50),
            'direction_id' => $responsable->direction_id,
        ]);
        $autreDirection = Stagiaire::factory()->create([
            'statut' => StagiaireStatut::EVALUATION_EN_COURS,
            'statut_change_at' => now()->subHours(50),
        ]);

        $reponse = $this->actingAs($responsable)->getJson('/api/v1/stagiaires/en-souffrance')->assertOk();

        $lignes = collect($reponse->json('data'));
        $this->assertTrue($lignes->contains(fn ($l) => $l['stagiaire']['id'] === $lui->id));
        $this->assertFalse($lignes->contains(fn ($l) => $l['stagiaire']['id'] === $autreDirection->id));
    }
}
