<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Mail\StagiaireAffecteMail;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Notifications\StagiaireAffecteNotification;

/**
 * Lot 5 (verrouiller la diffusion) : "l'avis favorable ouvre l'instruction
 * du dossier, il ne vaut pas acceptation" — rien ne sort tant que le
 * tableau qui porte la proposition n'est pas approuvé par la DG. Un seul
 * test rejoue chaque canal de sortie (mail, notification, document
 * généré, étape suivante du cycle de vie) et vérifie son refus avant
 * approbation, puis sa réussite après.
 */
class VerrouDiffusionTest extends StagiaireTestCase
{
    use RefreshDatabase;

    public function test_aucun_canal_ne_soutre_avant_lapprobation_du_tableau_puis_tous_souvrent_apres(): void
    {
        Mail::fake();
        Notification::fake();

        $this->directionDfp();
        $direction = Direction::factory()->create(['actif' => true]);
        $dfp = User::factory()->agentDfp()->create();
        $responsable = User::factory()->responsableDirection($direction)->create();
        $reception = User::factory()->agentCircuitCourrier(Poste::RECEPTION, Direction::factory()->create())->create();
        $dg = User::factory()->agentCircuitCourrier(Poste::DG, Direction::factory()->create())->create();

        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::DOSSIER_RECU]);
        $this->imputerADfp($stagiaire);
        $this->actingAs($dfp)->postJson("/api/v1/stagiaires/{$stagiaire->id}/examiner-dossier")->assertOk();

        $idTableau = $this->actingAs($dfp)->postJson('/api/v1/tableaux-repartition', [
            'periode_debut' => now()->toDateString(),
            'periode_fin' => now()->addMonth()->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/lignes", [
            'stagiaire_id' => $stagiaire->id,
            'direction_accueil_proposee_id' => $direction->id,
            'date_debut_proposee' => now()->addMonth()->toDateString(),
            'date_fin_proposee' => now()->addMonths(3)->toDateString(),
            'encadrant_pressenti' => 'Jean Mbala',
        ])->assertOk();

        // --- Avant approbation : "en instruction", rien ne sort. ---
        $this->assertSame(StagiaireStatut::EN_INSTRUCTION, $stagiaire->fresh()->statut);
        $this->assertNull($stagiaire->fresh()->direction_id);
        $this->assertNull($stagiaire->fresh()->matricule);

        // Canal 1 : aucun document (note d'affectation) généré.
        $this->assertDatabaseMissing('stagiaire_documents', ['stagiaire_id' => $stagiaire->id]);

        // Canal 2 : aucun mail, aucune notification.
        Mail::assertNothingQueued();
        Notification::assertNothingSent();

        // Canal 3 : l'étape suivante du cycle de vie (arrivée réelle, qui
        // génère elle-même convention + engagement de confidentialité et
        // leurs propres notifications) reste bloquée, faute d'affectation
        // réelle (statut AFFECTE requis).
        $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/valider-arrivee", [
                'date_debut_stage' => now()->addDays(5)->toDateString(),
                'date_fin_stage' => now()->addDays(65)->toDateString(),
            ])
            ->assertStatus(422);

        $this->assertNull($stagiaire->fresh()->convention_chemin);
        $this->assertNull($stagiaire->fresh()->engagement_confidentialite_chemin);

        // --- Le tableau transite jusqu'à l'approbation. ---
        $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/soumettre")->assertOk();
        $this->actingAs($reception)->postJson("/api/v1/tableaux-repartition/{$idTableau}/representer-dg")->assertOk();
        $this->actingAs($dg)
            ->postJson("/api/v1/tableaux-repartition/{$idTableau}/rendre-avis", ['approuve' => true])
            ->assertOk();

        // --- Après approbation : une seule action, tout en découle. ---
        $stagiaire->refresh();
        $this->assertSame(StagiaireStatut::AFFECTE, $stagiaire->statut);
        $this->assertSame($direction->id, $stagiaire->direction_id);
        $this->assertNotNull($stagiaire->matricule);

        $this->assertDatabaseHas('stagiaire_documents', ['stagiaire_id' => $stagiaire->id, 'type' => 'note_affectation']);

        Mail::assertQueued(StagiaireAffecteMail::class, fn ($mail) => $mail->hasTo($responsable->email));
        Notification::assertSentTo($responsable, StagiaireAffecteNotification::class);

        // L'arrivée réelle, elle, s'ouvre désormais.
        $this->actingAs($dfp)
            ->postJson("/api/v1/stagiaires/{$stagiaire->id}/valider-arrivee", [
                'date_debut_stage' => now()->addDays(5)->toDateString(),
                'date_fin_stage' => now()->addDays(65)->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.statut', StagiaireStatut::STAGE_EN_COURS->value);

        $this->assertNotNull($stagiaire->fresh()->convention_chemin);
        $this->assertNotNull($stagiaire->fresh()->engagement_confidentialite_chemin);
    }
}
