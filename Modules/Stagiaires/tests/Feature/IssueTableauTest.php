<?php

namespace Modules\Stagiaires\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Models\Direction;
use Modules\Kernel\Models\User;
use Modules\Stagiaires\Enums\StagiaireStatut;
use Modules\Stagiaires\Models\Stagiaire;
use Modules\Stagiaires\Notifications\IssueTableauNotification;
use Modules\Stagiaires\Services\StagiaireCircuitService;

/**
 * Lot B (issue individuelle) : un dossier non retenu sort de la boucle
 * comme un dossier retenu — classé, jamais remis en circulation dans les
 * files de traitement — et la diffusion (retenu ou non retenu) devient un
 * acte enregistré, avec la possibilité de renvoyer.
 */
class IssueTableauTest extends StagiaireTestCase
{
    use RefreshDatabase;

    private function creerTableauAvecLigne(Stagiaire $stagiaire, Direction $direction, User $dfp, string $issue, ?string $motif = null): int
    {
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
            'issue_proposee' => $issue,
            'motif_non_retenu' => $motif,
        ])->assertOk();

        return $idTableau;
    }

    public function test_un_dossier_non_retenu_sort_de_la_boucle_avec_son_motif(): void
    {
        Notification::fake();
        $directionDfp = $this->directionDfp();
        $direction = Direction::factory()->create();
        $dfp = User::factory()->agentDfp()->create();
        $reception = User::factory()->agentCircuitCourrier(Poste::RECEPTION, Direction::factory()->create())->create();
        $dg = User::factory()->agentCircuitCourrier(Poste::DG, Direction::factory()->create())->create();

        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION, 'contact' => 'candidat@example.com']);
        $this->imputerADfp($stagiaire, $directionDfp);

        $idTableau = $this->creerTableauAvecLigne($stagiaire, $direction, $dfp, 'non_retenu', 'places_epuisees');

        $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/soumettre")->assertOk();
        $this->actingAs($reception)->postJson("/api/v1/tableaux-repartition/{$idTableau}/representer-dg")->assertOk();
        $this->actingAs($dg)->postJson("/api/v1/tableaux-repartition/{$idTableau}/rendre-avis", ['approuve' => true])->assertOk();

        $stagiaire->refresh();
        $this->assertSame(StagiaireStatut::NON_RETENU, $stagiaire->statut);
        $this->assertSame('places_epuisees', $stagiaire->motif_non_retenu);
        $this->assertNotNull($stagiaire->non_retenu_at);
        $this->assertNull($stagiaire->direction_id);

        Notification::assertSentOnDemand(
            IssueTableauNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'candidat@example.com' && ! $notification->retenu,
        );
    }

    public function test_un_dossier_non_retenu_napparait_plus_parmi_les_dossiers_en_attente_daffectation(): void
    {
        $directionDfp = $this->directionDfp();
        $direction = Direction::factory()->create();
        $dfp = User::factory()->agentDfp()->create();
        $reception = User::factory()->agentCircuitCourrier(Poste::RECEPTION, Direction::factory()->create())->create();
        $dg = User::factory()->agentCircuitCourrier(Poste::DG, Direction::factory()->create())->create();

        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::EN_ATTENTE_AFFECTATION]);
        $this->imputerADfp($stagiaire, $directionDfp);
        $idTableau = $this->creerTableauAvecLigne($stagiaire, $direction, $dfp, 'non_retenu', 'dossier_incomplet');
        $this->actingAs($dfp)->postJson("/api/v1/tableaux-repartition/{$idTableau}/soumettre")->assertOk();
        $this->actingAs($reception)->postJson("/api/v1/tableaux-repartition/{$idTableau}/representer-dg")->assertOk();
        $this->actingAs($dg)->postJson("/api/v1/tableaux-repartition/{$idTableau}/rendre-avis", ['approuve' => true])->assertOk();

        $reponse = $this->actingAs($dfp)->getJson('/api/v1/stagiaires?statut=en_attente_affectation')->assertOk();
        $this->assertFalse(collect($reponse->json('data'))->pluck('id')->contains($stagiaire->id));
    }

    public function test_lissue_retenue_declenche_une_notification_enregistree_au_candidat(): void
    {
        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::AFFECTE, 'contact' => 'candidat@example.com']);
        $dfp = User::factory()->agentDfp()->create();

        Mail::fake();
        app(StagiaireCircuitService::class)->notifierIssue($stagiaire, $dfp);

        $this->assertDatabaseHas('notifications_diffusion', [
            'stagiaire_id' => $stagiaire->id,
            'type' => 'retenu',
            'canal' => 'email',
            'destinataire' => 'candidat@example.com',
        ]);
    }

    public function test_la_dfp_peut_renvoyer_une_notification_de_diffusion(): void
    {
        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::AFFECTE, 'contact' => 'candidat@example.com']);
        $dfp = User::factory()->agentDfp()->create();

        Mail::fake();
        $notification = app(StagiaireCircuitService::class)->notifierIssue($stagiaire);

        // 201 : le renvoi crée une nouvelle ligne de notification (jamais
        // une mise à jour de l'existante — voir NotificationDiffusion,
        // append-only), donc le modèle retourné a wasRecentlyCreated=true.
        $this->actingAs($dfp)
            ->postJson("/api/v1/notifications-diffusion/{$notification->id}/renvoyer")
            ->assertCreated();

        $this->assertSame(2, $stagiaire->notificationsDiffusion()->count());
    }

    public function test_seule_la_dfp_peut_voir_lhistorique_des_notifications(): void
    {
        $stagiaire = Stagiaire::factory()->create(['statut' => StagiaireStatut::AFFECTE]);
        // L'administrateur voit toutes les directions (bypass du
        // DirectionScope), mais gererDossier() n'autorise que la DFP — un
        // responsableDirection, lui, ne verrait de toute façon jamais ce
        // dossier (DirectionScope le filtre avant même la policy), ce qui
        // donnerait un 404 et ne testerait pas la policy elle-même.
        $administrateur = User::factory()->administrateur()->create();

        $this->actingAs($administrateur)
            ->getJson("/api/v1/stagiaires/{$stagiaire->id}/notifications-diffusion")
            ->assertForbidden();
    }
}
