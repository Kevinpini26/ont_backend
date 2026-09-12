<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Courrier\Enums\AvisDg;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DgDisponibilite;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\NotificationDiffusion;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Models\TableauRepartition;

/**
 * Critère d'acceptation final (Partie 3 du chantier) : rejoue une session
 * complète du circuit "demande de stage" de bout en bout, tous les lots
 * (A à D) impliqués dans un seul scénario réaliste — 15 demandes,
 * orientation DG→DFP, tableau généré sans ressaisie, 12 retenues/3
 * refusées, soumis, renvoyé une fois, corrigé, approuvé en intérim DGA
 * pendant l'absence de la DG, notifications tracées, dossiers classés.
 */
class SessionCompleteAcceptanceTest extends StagiaireTestCase
{
    use RefreshDatabase;

    public function test_une_session_complete_de_15_demandes_jusquau_classement(): void
    {
        Notification::fake();

        $directionDfp = $this->directionDfp();
        $directionAccueil = Direction::factory()->create(['capacite_max' => null]);

        $dfp = User::factory()->agentDfp()->create();
        $secretariat1 = User::factory()->agentCircuitCourrier(Poste::SECRETARIAT_1, Direction::factory()->create())->create();
        $reception = User::factory()->agentCircuitCourrier(Poste::RECEPTION, Direction::factory()->create())->create();
        $dg = User::factory()->agentCircuitCourrier(Poste::DG, Direction::factory()->create())->create();
        $dga = User::factory()->agentCircuitCourrier(Poste::DGA, Direction::factory()->create())->create();

        // --- 15 demandes déposées, chacune orientée par la DG vers la DFP,
        // sans jamais imputer manuellement : l'avis favorable suffit (voir
        // config('stagiaires.imputation_automatique_dfp')).
        $courriers = [];
        for ($i = 0; $i < 15; $i++) {
            $courrier = Courrier::factory()->demandeStage()->create(['statut' => CourrierStatut::EN_ATTENTE_AVIS_DG]);
            $this->marquerDecharge($courrier);

            $this->actingAs($dg)
                ->postJson("/api/v1/courriers/{$courrier->id}/rendre-avis", ['avis_dg' => AvisDg::FAVORABLE->value])
                ->assertOk();

            $courriers[] = $courrier->fresh();
        }

        // Chaque avis favorable a déclenché la création automatique de la
        // fiche stagiaire (CreerFicheStagiaireDepuisCourrier), à partir des
        // seules données déjà connues du courrier — aucune ressaisie.
        $stagiaires = Stagiaire::query()->whereIn('courrier_id', collect($courriers)->pluck('id'))->get();
        $this->assertCount(15, $stagiaires);
        foreach ($stagiaires as $stagiaire) {
            $courrier = collect($courriers)->firstWhere('id', $stagiaire->courrier_id);
            $this->assertSame($courrier->candidat_nom, $stagiaire->nom);
            $this->assertSame($courrier->candidat_contact, $stagiaire->contact);
        }

        // La DFP examine chaque dossier (dossier_recu -> en_attente_affectation).
        foreach ($stagiaires as $stagiaire) {
            $this->actingAs($dfp)->postJson("/api/v1/stagiaires/{$stagiaire->id}/examiner-dossier")->assertOk();
        }

        // --- Tableau généré sans ressaisie : la DFP sélectionne les 15
        // dossiers déjà éligibles, ne retape aucune information connue.
        $eligibles = $this->actingAs($dfp)->getJson('/api/v1/tableaux-repartition/dossiers-eligibles')->assertOk()->json('data');
        $this->assertCount(15, $eligibles);

        $idTableau = $this->actingAs($dfp)->postJson('/api/v1/tableaux-repartition', [
            'periode_debut' => now()->toDateString(),
            'periode_fin' => now()->addMonth()->toDateString(),
        ])->assertCreated()->json('data.id');

        $stagiairesTries = $stagiaires->values();
        $lignes = [];
        foreach ($stagiairesTries as $index => $stagiaire) {
            // 12 retenues, 3 refusées.
            $nonRetenu = $index >= 12;
            $lignes[] = [
                'stagiaire_id' => $stagiaire->id,
                'direction_accueil_proposee_id' => $directionAccueil->id,
                'date_debut_proposee' => now()->addMonth()->toDateString(),
                'date_fin_proposee' => now()->addMonths(3)->toDateString(),
                'encadrant_pressenti' => 'Encadrant '.$index,
                'issue_proposee' => $nonRetenu ? 'non_retenu' : 'retenu',
                'motif_non_retenu' => $nonRetenu ? 'places_epuisees' : null,
            ];
        }

        $this->actingAs($dfp)
            ->postJson("/api/v1/tableaux-repartition/{$idTableau}/lignes/lot", ['lignes' => $lignes])
            ->assertOk()
            ->assertJsonCount(15, 'data.lignes');

        // Soumis, puis présenté à la DG.
        $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/soumettre")->assertOk();
        $this->actingAs($reception)->postJson("/api/v1/tableaux-repartition/{$idTableau}/representer-dg")->assertOk();

        // --- Renvoyé une fois, avec observations : le tour augmente,
        // aucune affectation n'est encore réelle.
        $this->actingAs($dg)
            ->postJson("/api/v1/tableaux-repartition/{$idTableau}/rendre-avis", [
                'approuve' => false,
                'observations' => 'Vérifier les dates proposées avant nouvelle présentation.',
            ])
            ->assertOk()
            ->assertJsonPath('data.statut', 'chez_reception');

        foreach ($stagiaires as $stagiaire) {
            $this->assertSame(StagiaireStatut::EN_INSTRUCTION, $stagiaire->refresh()->statut);
        }

        // --- Corrigé (rien à changer sur le fond ici) puis re-présenté :
        // c'est ce second passage qui incrémente le tour.
        $reponsePresentation = $this->actingAs($reception)
            ->postJson("/api/v1/tableaux-repartition/{$idTableau}/representer-dg")
            ->assertOk();
        $this->assertSame(2, $reponsePresentation->json('data.tour'));

        // --- Approuvé en intérim DGA, la DG étant marquée indisponible.
        DgDisponibilite::definir(false);

        $reponseApprobation = $this->actingAs($dga)
            ->postJson("/api/v1/tableaux-repartition/{$idTableau}/rendre-avis", ['approuve' => true])
            ->assertOk()
            ->json('data');

        $this->assertSame('approuve', $reponseApprobation['statut']);
        $this->assertTrue($reponseApprobation['scellement']['mention_interim']);
        $this->assertNotNull($reponseApprobation['cote_classement']);

        // --- 12 retenues affectées, 3 refusées non retenues.
        $stagiaires->each->refresh();
        $retenus = $stagiaires->filter(fn ($s) => $s->statut === StagiaireStatut::AFFECTE);
        $nonRetenus = $stagiaires->filter(fn ($s) => $s->statut === StagiaireStatut::NON_RETENU);
        $this->assertCount(12, $retenus);
        $this->assertCount(3, $nonRetenus);

        // --- Notifications tracées : une par stagiaire, quelle que soit
        // l'issue (Lot B, verrou de diffusion).
        $this->assertSame(15, NotificationDiffusion::query()->count());
        foreach ($stagiaires as $stagiaire) {
            $this->assertSame(1, $stagiaire->notificationsDiffusion()->count());
        }

        // --- Dossiers classés : chaque lettre et le tableau lui-même
        // portent désormais leur propre cote (Lot D).
        foreach ($stagiaires as $stagiaire) {
            $stagiaire->load('courrier');
            $this->assertNotNull($stagiaire->courrier->cote_classement);
        }

        $tableau = TableauRepartition::query()->findOrFail($idTableau);
        $this->assertNotNull($tableau->cote_classement);
        $this->assertTrue($tableau->scellement()->exists());
    }
}
