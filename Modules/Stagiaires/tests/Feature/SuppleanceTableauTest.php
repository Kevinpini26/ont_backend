<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\DelegationPoste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;

/**
 * Lot C, point 4 : la suppléance de tous les postes (DelegationPoste /
 * DelegationResolver, déjà générique côté courrier) doit aussi couvrir le
 * circuit du tableau de répartition — jusqu'ici, seul le titulaire exact du
 * poste Réception, ou la DGA via DgDisponibilite, pouvait y agir.
 */
class SuppleanceTableauTest extends StagiaireTestCase
{
    use RefreshDatabase;

    private function creerDelegation(Poste $poste, User $delegataire): void
    {
        DelegationPoste::query()->create([
            'poste' => $poste,
            'delegataire_id' => $delegataire->id,
            'debut' => now()->toDateString(),
            'fin' => now()->addDay()->toDateString(),
            'motif' => 'Test',
            'cree_par_id' => $delegataire->id,
        ]);
    }

    public function test_un_delegataire_de_la_reception_peut_representer_le_tableau_a_la_dg(): void
    {
        $directionDfp = $this->directionDfp();
        $direction = Direction::factory()->create();
        $dfp = User::factory()->agentDfp()->create();
        $delegataire = User::factory()->agentCircuitCourrier(Poste::SECRETARIAT_2, Direction::factory()->create())->create();
        $this->creerDelegation(Poste::RECEPTION, $delegataire);

        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);
        $this->imputerADfp($stagiaire, $directionDfp);

        $idTableau = $this->actingAs($dfp)->postJson('/api/v1/tableaux-repartition', [
            'periode_debut' => now()->toDateString(),
            'periode_fin' => now()->addMonth()->toDateString(),
        ])->json('data.id');

        $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/lignes", [
            'stagiaire_id' => $stagiaire->id,
            'direction_accueil_proposee_id' => $direction->id,
            'date_debut_proposee' => now()->addMonth()->toDateString(),
            'date_fin_proposee' => now()->addMonths(3)->toDateString(),
            'encadrant_pressenti' => 'x',
        ])->assertOk();

        $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/soumettre")->assertOk();

        // Le titulaire réel de la Réception, lui, n'a rien à voir ici : la
        // délégation suffit à elle seule.
        $this->actingAs($delegataire)
            ->postJson("/api/v1/tableaux-repartition/{$idTableau}/representer-dg")
            ->assertOk();

        $this->assertDatabaseHas('courrier_transitions', [
            'agi_en_interim' => true,
        ]);
    }

    public function test_un_delegataire_du_poste_dg_peut_rendre_lavis_sur_le_tableau(): void
    {
        $directionDfp = $this->directionDfp();
        $direction = Direction::factory()->create();
        $dfp = User::factory()->agentDfp()->create();
        $reception = User::factory()->agentCircuitCourrier(Poste::RECEPTION, Direction::factory()->create())->create();
        $delegataire = User::factory()->agentCircuitCourrier(Poste::SECRETARIAT_1, Direction::factory()->create())->create();
        $this->creerDelegation(Poste::DG, $delegataire);

        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);
        $this->imputerADfp($stagiaire, $directionDfp);

        $idTableau = $this->actingAs($dfp)->postJson('/api/v1/tableaux-repartition', [
            'periode_debut' => now()->toDateString(),
            'periode_fin' => now()->addMonth()->toDateString(),
        ])->json('data.id');

        $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/lignes", [
            'stagiaire_id' => $stagiaire->id,
            'direction_accueil_proposee_id' => $direction->id,
            'date_debut_proposee' => now()->addMonth()->toDateString(),
            'date_fin_proposee' => now()->addMonths(3)->toDateString(),
            'encadrant_pressenti' => 'x',
        ])->assertOk();

        $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/soumettre")->assertOk();
        $this->actingAs($reception)->postJson("/api/v1/tableaux-repartition/{$idTableau}/representer-dg")->assertOk();

        $this->actingAs($delegataire)
            ->postJson("/api/v1/tableaux-repartition/{$idTableau}/rendre-avis", ['approuve' => true])
            ->assertOk();

        $stagiaire->refresh();
        $this->assertSame(StagiaireStatut::AFFECTE, $stagiaire->statut);
    }
}
