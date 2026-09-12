<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Enums\TableauRepartitionStatut;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Models\TableauRepartition;

/**
 * Lot 4 (tableau de répartition) : la DFP regroupe des demandes de stage
 * "en attente d'affectation" dans un tableau, le soumet, il transite par
 * la Réception jusqu'à la Direction Générale, qui l'approuve en bloc ou le
 * renvoie avec observations (renvoi = tour suivant). Voir
 * TableauRepartitionCircuitService.
 */
class TableauRepartitionTest extends StagiaireTestCase
{
    use RefreshDatabase;

    private function stagiaireEnAttente(): Stagiaire
    {
        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);
        $this->imputerADfp($stagiaire);

        return $stagiaire;
    }

    public function test_le_circuit_complet_de_soumission_a_lapprobation(): void
    {
        $this->directionDfp();
        $directionAccueil = Direction::factory()->create();
        $dfp = User::factory()->agentDfp()->create();
        $reception = User::factory()->agentCircuitCourrier(Poste::RECEPTION, Direction::factory()->create())->create();
        $dg = User::factory()->agentCircuitCourrier(Poste::DG, Direction::factory()->create())->create();
        $stagiaire = $this->stagiaireEnAttente();

        // Création + ajout d'une ligne.
        $idTableau = $this->actingAs($dfp)->postJson('/api/v1/tableaux-repartition', [
            'periode_debut' => '2026-09-01',
            'periode_fin' => '2026-09-30',
        ])->assertCreated()->json('data.id');

        $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/lignes", [
            'stagiaire_id' => $stagiaire->id,
            'direction_accueil_proposee_id' => $directionAccueil->id,
            'date_debut_proposee' => '2026-10-01',
            'date_fin_proposee' => '2026-12-01',
            'encadrant_pressenti' => 'Jean Mbala',
        ])->assertOk()->assertJsonCount(1, 'data.lignes');

        // Soumission.
        $reponseSoumission = $this->actingAs($dfp)
            ->postJson("/api/v1/tableaux-repartition/{$idTableau}/soumettre")
            ->assertOk();
        $this->assertSame(TableauRepartitionStatut::CHEZ_RECEPTION->value, $reponseSoumission->json('data.statut'));

        // Un tableau soumis n'est plus modifiable.
        $autreStagiaire = $this->stagiaireEnAttente();
        $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/lignes", [
            'stagiaire_id' => $autreStagiaire->id,
            'direction_accueil_proposee_id' => $directionAccueil->id,
            'date_debut_proposee' => '2026-10-01',
            'date_fin_proposee' => '2026-12-01',
            'encadrant_pressenti' => 'x',
        ])->assertStatus(422);

        // La Réception présente à la DG.
        $reponseRepresentation = $this->actingAs($reception)
            ->postJson("/api/v1/tableaux-repartition/{$idTableau}/representer-dg")
            ->assertOk();
        $this->assertSame(TableauRepartitionStatut::EN_ATTENTE_AVIS_DG->value, $reponseRepresentation->json('data.statut'));
        $this->assertSame(1, $reponseRepresentation->json('data.tour'));

        // La DG approuve.
        $reponseApprobation = $this->actingAs($dg)
            ->postJson("/api/v1/tableaux-repartition/{$idTableau}/rendre-avis", ['approuve' => true])
            ->assertOk();
        $this->assertSame(TableauRepartitionStatut::APPROUVE->value, $reponseApprobation->json('data.statut'));
        $this->assertTrue($reponseApprobation->json('data.pdf_disponible'));

        $this->actingAs($dg)
            ->getJson("/api/v1/tableaux-repartition/{$idTableau}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_un_renvoi_avec_observations_incremente_le_tour_et_revient_a_la_reception(): void
    {
        $this->directionDfp();
        $directionAccueil = Direction::factory()->create();
        $dfp = User::factory()->agentDfp()->create();
        $reception = User::factory()->agentCircuitCourrier(Poste::RECEPTION, Direction::factory()->create())->create();
        $dg = User::factory()->agentCircuitCourrier(Poste::DG, Direction::factory()->create())->create();
        $stagiaire = $this->stagiaireEnAttente();

        $idTableau = $this->actingAs($dfp)->postJson('/api/v1/tableaux-repartition', [
            'periode_debut' => '2026-09-01',
            'periode_fin' => '2026-09-30',
        ])->json('data.id');

        $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/lignes", [
            'stagiaire_id' => $stagiaire->id,
            'direction_accueil_proposee_id' => $directionAccueil->id,
            'date_debut_proposee' => '2026-10-01',
            'date_fin_proposee' => '2026-12-01',
            'encadrant_pressenti' => 'Jean Mbala',
        ]);

        $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/soumettre");
        $this->actingAs($reception)->postJson("/api/v1/tableaux-repartition/{$idTableau}/representer-dg");

        // Renvoi sans observation refusé.
        $this->actingAs($dg)
            ->postJson("/api/v1/tableaux-repartition/{$idTableau}/rendre-avis", ['approuve' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['observations']);

        // Renvoi motivé.
        $reponseRenvoi = $this->actingAs($dg)
            ->postJson("/api/v1/tableaux-repartition/{$idTableau}/rendre-avis", [
                'approuve' => false,
                'observations' => 'Encadrant à confirmer pour deux dossiers.',
            ])
            ->assertOk();
        $this->assertSame(TableauRepartitionStatut::CHEZ_RECEPTION->value, $reponseRenvoi->json('data.statut'));

        // Deuxième présentation : le tour passe à 2.
        $representation2 = $this->actingAs($reception)
            ->postJson("/api/v1/tableaux-repartition/{$idTableau}/representer-dg")
            ->assertOk();
        $this->assertSame(2, $representation2->json('data.tour'));
    }

    public function test_un_tableau_vide_ne_peut_pas_etre_soumis(): void
    {
        $this->directionDfp();
        $dfp = User::factory()->agentDfp()->create();

        $idTableau = $this->actingAs($dfp)->postJson('/api/v1/tableaux-repartition', [
            'periode_debut' => '2026-09-01',
            'periode_fin' => '2026-09-30',
        ])->json('data.id');

        $this->actingAs($dfp)
            ->postJson("/api/v1/tableaux-repartition/{$idTableau}/soumettre")
            ->assertStatus(422);
    }

    public function test_seule_la_dfp_peut_ajouter_une_ligne(): void
    {
        $this->directionDfp();
        $directionAccueil = Direction::factory()->create();
        $dfp = User::factory()->agentDfp()->create();
        $responsable = User::factory()->responsableDirection()->create();
        $stagiaire = $this->stagiaireEnAttente();

        $idTableau = $this->actingAs($dfp)->postJson('/api/v1/tableaux-repartition', [
            'periode_debut' => '2026-09-01',
            'periode_fin' => '2026-09-30',
        ])->json('data.id');

        $this->actingAs($responsable)->postJson("/api/v1/tableaux-repartition/{$idTableau}/lignes", [
            'stagiaire_id' => $stagiaire->id,
            'direction_accueil_proposee_id' => $directionAccueil->id,
            'date_debut_proposee' => '2026-10-01',
            'date_fin_proposee' => '2026-12-01',
            'encadrant_pressenti' => 'x',
        ])->assertForbidden();
    }

    public function test_un_dossier_pas_encore_en_attente_daffectation_est_refuse(): void
    {
        $this->directionDfp();
        $directionAccueil = Direction::factory()->create();
        $dfp = User::factory()->agentDfp()->create();
        $stagiaireNonExamine = Stagiaire::factory()->create(['statut' => StagiaireStatut::DOSSIER_RECU]);

        $idTableau = $this->actingAs($dfp)->postJson('/api/v1/tableaux-repartition', [
            'periode_debut' => '2026-09-01',
            'periode_fin' => '2026-09-30',
        ])->json('data.id');

        $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/lignes", [
            'stagiaire_id' => $stagiaireNonExamine->id,
            'direction_accueil_proposee_id' => $directionAccueil->id,
            'date_debut_proposee' => '2026-10-01',
            'date_fin_proposee' => '2026-12-01',
            'encadrant_pressenti' => 'x',
        ])->assertStatus(422);
    }

    /**
     * Une demande de stage ne peut figurer que dans un seul tableau
     * approuvé : la seconde approbation (autre tableau, même stagiaire) est
     * refusée en bloc, y compris pour les lignes qui n'étaient pas en
     * conflit.
     */
    public function test_une_demande_deja_dans_un_tableau_approuve_bloque_lapprobation_dun_second(): void
    {
        $this->directionDfp();
        $directionAccueil = Direction::factory()->create();
        $dfp = User::factory()->agentDfp()->create();
        $reception = User::factory()->agentCircuitCourrier(Poste::RECEPTION, Direction::factory()->create())->create();
        $dg = User::factory()->agentCircuitCourrier(Poste::DG, Direction::factory()->create())->create();
        $stagiaire = $this->stagiaireEnAttente();

        $creerEtApprouver = function () use ($dfp, $reception, $stagiaire, $directionAccueil) {
            $id = $this->actingAs($dfp)->postJson('/api/v1/tableaux-repartition', [
                'periode_debut' => '2026-09-01',
                'periode_fin' => '2026-09-30',
            ])->json('data.id');

            $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$id}/lignes", [
                'stagiaire_id' => $stagiaire->id,
                'direction_accueil_proposee_id' => $directionAccueil->id,
                'date_debut_proposee' => '2026-10-01',
                'date_fin_proposee' => '2026-12-01',
                'encadrant_pressenti' => 'x',
            ])->assertOk();
            $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$id}/soumettre")->assertOk();
            $this->actingAs($reception)->postJson("/api/v1/tableaux-repartition/{$id}/representer-dg")->assertOk();

            return $id;
        };

        $premierId = $creerEtApprouver();
        $this->actingAs($dg)->postJson("/api/v1/tableaux-repartition/{$premierId}/rendre-avis", ['approuve' => true])->assertOk();

        // Le même stagiaire remis "en attente d'affectation" (hypothèse de
        // test : un second cycle démarré par erreur avant que le Lot 5
        // ne verrouille quoi que ce soit). refresh() d'abord : $stagiaire
        // est resté sur son statut d'origine en mémoire (jamais relu
        // depuis la première approbation, qui l'a fait passer par
        // en_instruction puis affecte via d'autres instances) — sans lui,
        // Eloquent ne verrait aucun changement dirty et n'émettrait aucune
        // requête UPDATE.
        $stagiaire->refresh()->update(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);

        $secondId = $creerEtApprouver();
        $this->actingAs($dg)
            ->postJson("/api/v1/tableaux-repartition/{$secondId}/rendre-avis", ['approuve' => true])
            ->assertStatus(422);

        $this->assertSame(
            TableauRepartitionStatut::EN_ATTENTE_AVIS_DG,
            TableauRepartition::find($secondId)->statut,
        );
    }

    public function test_la_dga_ne_peut_pas_rendre_avis_si_la_dg_est_disponible(): void
    {
        $this->directionDfp();
        $directionAccueil = Direction::factory()->create();
        $dfp = User::factory()->agentDfp()->create();
        $reception = User::factory()->agentCircuitCourrier(Poste::RECEPTION, Direction::factory()->create())->create();
        $dga = User::factory()->agentCircuitCourrier(Poste::DGA, Direction::factory()->create())->create();
        $stagiaire = $this->stagiaireEnAttente();

        $id = $this->actingAs($dfp)->postJson('/api/v1/tableaux-repartition', [
            'periode_debut' => '2026-09-01',
            'periode_fin' => '2026-09-30',
        ])->json('data.id');

        $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$id}/lignes", [
            'stagiaire_id' => $stagiaire->id,
            'direction_accueil_proposee_id' => $directionAccueil->id,
            'date_debut_proposee' => '2026-10-01',
            'date_fin_proposee' => '2026-12-01',
            'encadrant_pressenti' => 'x',
        ]);
        $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$id}/soumettre");
        $this->actingAs($reception)->postJson("/api/v1/tableaux-repartition/{$id}/representer-dg");

        $this->actingAs($dga)
            ->postJson("/api/v1/tableaux-repartition/{$id}/rendre-avis", ['approuve' => true])
            ->assertStatus(422);
    }
}
